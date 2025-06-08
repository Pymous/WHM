<?php

namespace App\Services;

use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use App\Models\EveCharacter;
use Illuminate\Support\Facades\Log;
use App\Jobs\EveCharactersVerifyJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schedule;
use GuzzleHttp\Exception\GuzzleException;

class EveOnlineProvider extends ServiceProvider
{
    protected Client $client;
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;
    protected string $baseUrl = 'https://login.eveonline.com';
    protected string $esiUrl = 'https://esi.evetech.net';
    protected EveCharacter $caller;

    public function __construct()
    {
        $this->client = new Client();
        $this->clientId = config('services.eveonline.client_id');
        $this->clientSecret = config('services.eveonline.client_secret');
        $this->redirectUri = config('services.eveonline.redirect');
    }

    /**
     * Redirect the user to the EVE Online SSO authentication page.
     */
    public function redirect(): RedirectResponse
    {
        $state = Str::random(40);
        session(['eve_state' => $state]);

        $query = http_build_query([
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'scope' => config('services.eveonline.scopes'),
            'state' => $state,
        ]);

        return redirect()->away("{$this->baseUrl}/v2/oauth/authorize?{$query}");
    }

    /**
     * Handle the callback from EVE Online SSO.
     */
    public function handleCallback(string $code, string $state): ?EveCharacter
    {
        if ($state !== session('eve_state')) {
            Log::error('EVE Online SSO state mismatch');
            return null;
        }

        try {
            $response = $this->client->post("{$this->baseUrl}/v2/oauth/token", [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode("{$this->clientId}:{$this->clientSecret}"),
                ],
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);
            $characterData = $this->verifyToken($tokenData['access_token']);

            if (!$characterData) {
                return null;
            }

            return $this->createOrUpdateCharacter(
                Auth::user(),
                $characterData['CharacterID'],
                $characterData['CharacterName'],
                $tokenData['access_token'],
                $tokenData['refresh_token'],
                now()->addSeconds($tokenData['expires_in']),
                $characterData['Scopes'] ?? null
            );
        } catch (GuzzleException $e) {
            Log::error('EVE Online SSO token request failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Verify the JWT token from EVE Online.
     */
    protected function verifyToken(string $token): ?array
    {
        try {
            $response = $this->client->get("{$this->baseUrl}/oauth/verify", [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            Log::error('EVE Online SSO token verification failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Create or update an EVE character.
     */
    protected function createOrUpdateCharacter(?User $user, string $characterId, string $characterName, string $accessToken, string $refreshToken, $expiresAt, ?string $scopes = null): EveCharacter
    {
        // Check if we have a EveCharacter with that name already, and if yes, retrieve the user for it
        $character = EveCharacter::where('character_id', $characterId)->first();
        if ($character) {
            $user = $character->user;
        }

        // Check if we have a $user, if not, we have null and we need to create one
        if (!$user) {
            $user = User::updateOrCreate([
                'name' => $characterName,
            ]);
            Log::info('EVE Online SSO created new user', [
                'user_id' => $user->id,
                'character_id' => $characterId,
            ]);
        }
        // Force login the $user
        Auth::login($user, true);

        $character = EveCharacter::updateOrCreate(
            [
                'user_id' => $user->id,
                'character_id' => $characterId,
            ],
            [
                'name' => $characterName,
                'esi_access_token' => $accessToken,
                'esi_refresh_token' => $refreshToken,
                'esi_expires_at' => $expiresAt,
                'esi_scopes' => $scopes,
            ]
        );

        // If this is the user's first character, make it primary
        if ($user->eveCharacters()->count() === 1) {
            $character->update(['is_primary' => true]);
        }

        return $character;
    }

    /**
     * Refresh the ESI token for a character.
     */
    public function refreshToken(EveCharacter $character): bool
    {
        try {
            $response = $this->client->post("{$this->baseUrl}/v2/oauth/token", [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . base64_encode("{$this->clientId}:{$this->clientSecret}"),
                ],
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $character->esi_refresh_token,
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);

            $character->update([
                'esi_access_token' => $tokenData['access_token'],
                'esi_refresh_token' => $tokenData['refresh_token'] ?? $character->esi_refresh_token,
                'esi_expires_at' => now()->addSeconds($tokenData['expires_in']),
            ]);

            return true;
        } catch (GuzzleException $e) {
            Log::error('EVE Online SSO token refresh failed', [
                'error' => $e->getMessage(),
                'character_id' => $character->character_id,
            ]);
            return false;
        }
    }

    /**
     * Make an authenticated request to the ESI API.
     */
    public function request(EveCharacter $character, string $method, string $endpoint, array $options = []): ?array
    {
        if ($character->isTokenExpired()) {
            if (!$this->refreshToken($character)) {
                return null;
            }
        }

        try {
            $response = $this->client->request($method, "{$this->esiUrl}{$endpoint}", array_merge([
                'headers' => [
                    'Authorization' => "Bearer {$character->esi_access_token}",
                    'Content-Type' => 'application/json',
                ],
            ], $options));

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            Log::error('ESI API request failed', [
                'error' => $e->getMessage(),
                'endpoint' => $endpoint,
                'character_id' => $character->character_id,
            ]);
            return null;
        }
    }

    public function getCorporation(int $corpId): ?array
    {
        try {
            $response = $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/", [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            Log::error('Failed to retrieve corporation details', [
                'error' => $e->getMessage(),
                'corp_id' => $corpId,
            ]);
            return null;
        }
    }

    public function getCorporationMembersTitles()
    {
        try {
            $corpId = env('EVE_CORPORATION_ID');
            if (!$corpId) {
                Log::error('Corporation ID is not set in the environment variables.');
                return null;
            }

            if (!isset($this->caller)) {
                Log::error('No caller set for authenticated ESI request.');
                return null;
            }

            $response = $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/membertracking", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);

            /*
            Example of Member Tracking Object in the response : 
            {
                "character_id": 90875173,
                "location_id": 60009928,
                "logoff_date": "2025-06-06T15:43:54Z",
                "logon_date": "2025-06-06T18:11:48Z",
                "ship_type_id": 28606,
                "start_date": "2025-06-04T16:19:00Z"
            },
            */
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            Log::error('Failed to retrieve corporation members', [
                'error' => $e->getMessage(),
                'corp_id' => $corpId,
            ]);
            return null;
        }
    }

    public function getCorporationMembersTracking()
    {

        try {
            $corpId = env('EVE_CORPORATION_ID');
            if (!$corpId) {
                Log::error('Corporation ID is not set in the environment variables.');
                return null;
            }

            if (!isset($this->caller)) {
                Log::error('No caller set for authenticated ESI request.');
                return null;
            }

            $response = $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/members/titles", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            Log::error('Failed to retrieve corporation members', [
                'error' => $e->getMessage(),
                'corp_id' => $corpId,
            ]);
            return null;
        }
    }

    public function verify()
    {
        try {
            $response = $this->client->get("{$this->esiUrl}/verify", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            Log::error('Failed to verify EVE Online character', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function getCorporationTitles()
    {
        try {
            $corpId = env('EVE_CORPORATION_ID');
            if (!$corpId) {
                Log::error('Corporation ID is not set in the environment variables.');
                return null;
            }

            if (!isset($this->caller)) {
                Log::error('No caller set for authenticated ESI request.');
                return null;
            }

            $response = $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/titles", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);

            /*
            Example of Title Object in the response : 
            {
                "grantable_roles": [],
                "grantable_roles_at_base": [],
                "grantable_roles_at_hq": [],
                "grantable_roles_at_other": [],
                "name": "Member",
                "roles": [],
                "roles_at_base": [
                "Hangar_Take_7",
                "Hangar_Query_7",
                "Container_Take_7"
                ],
                "roles_at_hq": [
                "Hangar_Take_7",
                "Hangar_Query_7",
                "Container_Take_7"
                ],
                "roles_at_other": [
                "Deliveries_Query",
                "Hangar_Take_7",
                "Hangar_Query_1",
                "Hangar_Query_7",
                "Container_Take_7"
                ],
                "title_id": 4
            },
            */

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            Log::error('Failed to retrieve corporation titles', [
                'error' => $e->getMessage(),
                'corp_id' => $corpId,
            ]);
            return null;
        }
    }

    public function getCorporationDirectors()
    {
        try {
            $corpId = env('EVE_CORPORATION_ID');
            if (!$corpId) {
                Log::error('Corporation ID is not set in the environment variables.');
                return null;
            }

            if (!isset($this->caller)) {
                Log::error('No caller set for authenticated ESI request.');
                return null;
            }

            $response = $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/roles", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            $directors = [];

            // Loop through the roles and find the directors
            foreach ($responseData as $member) {
                // Check if $member['grantable_roles'] contain "Director" and if so, add it to the $directors array
                if (in_array('Director', $member['grantable_roles'])) {
                    $directors[] = $member['character_id'];
                }
            }

            return $directors;
        } catch (GuzzleException $e) {
            Log::error('Failed to retrieve corporation directors', [
                'error' => $e->getMessage(),
                'corp_id' => $corpId,
            ]);
            return null;
        }
    }

    public function getMainCeo(): ?EveCharacter
    {
        // Get the .env EVE_CORPORATION_ID
        $corpId = env('EVE_CORPORATION_ID');
        if (!$corpId) {
            Log::error('Corporation ID is not set in the environment variables.');
            return null;
        }

        // Get the corporation details using the getCorporation method of the provider
        $corporation = $this->getCorporation($corpId);
        if (!$corporation) {
            Log::error('Failed to retrieve corporation details.');
            return null;
        }

        // Check if we have a EveCharacter in the database associated with the CEO $corporation['ceo_id'], and get his user
        $ceoCharacter = EveCharacter::where('character_id', $corporation['ceo_id'])->first();
        if (!$ceoCharacter) {
            Log::error('CEO character not found in the database.', [
                'ceo_id' => $corporation['ceo_id'],
            ]);
            return null;
        }

        return $ceoCharacter ?? null;
    }

    public function setCaller(EveCharacter $character): void
    {
        $this->caller = $character;
        // Check if the caller token is expired, if yes, refresh it
        if ($this->caller->isTokenExpired()) {
            if (!$this->refreshToken($this->caller)) {
                Log::error('Failed to refresh token for caller character.', [
                    'character_id' => $this->caller->character_id,
                ]);
            }
        }
    }
}
