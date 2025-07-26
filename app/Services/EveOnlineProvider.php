<?php

namespace App\Services;

use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use App\Models\EveCharacter;
use App\Models\EveCorporation;
use Illuminate\Support\Facades\Log;
use App\Jobs\EveCharactersVerifyJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schedule;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ClientException;

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
                'is_valid' => true,
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
            if ($e->getCode() === 400) {
                // If the refresh token is invalid, mark the character as invalid
                $character->update(['is_valid' => false]);
                return false;
            }


            Log::error('EVE Online SSO token refresh failed', [
                'error' => $e->getMessage(),
                'character_id' => $character->character_id,
            ]);
            return false;
        }
    }

    /**
     * Helper method to handle API responses and catch specific error codes.
     */
    protected function handleApiRequest(callable $requestFunction): ?array
    {
        try {
            $response = $requestFunction();
            return json_decode($response->getBody()->getContents(), true);
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if ($statusCode === 401) {
                // Access the request in $request :
                $request = $e->getRequest()->getHeader('Authorization');
                // Remove "Bearer " from the request
                $token = str_replace('Bearer ', '', $request[0] ?? '');

                // Update the EVECharacter.is_valid at false that hold the $token
                $character = EveCharacter::where('esi_access_token', $token)->first();
                if ($character) {
                    $character->update(['is_valid' => false]);
                }

                return null;
            }

            Log::error('EVE ESI API request failed', [
                'status_code' => $statusCode,
                'error' => $e->getMessage(),
            ]);
            return null;
        } catch (GuzzleException $e) {
            Log::error('EVE ESI API request failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
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

        return $this->handleApiRequest(function () use ($method, $endpoint, $options, $character) {
            return $this->client->request($method, "{$this->esiUrl}{$endpoint}", array_merge([
                'headers' => [
                    'Authorization' => "Bearer {$character->esi_access_token}",
                    'Content-Type' => 'application/json',
                ],
            ], $options));
        });
    }

    public function getCorporation(int $corpId): ?array
    {
        return $this->handleApiRequest(function () use ($corpId) {
            return $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/", [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    public function getCorporationMembersTitles()
    {
        $corpId = env('EVE_CORPORATION_ID');
        if (!$corpId) {
            Log::error('Corporation ID is not set in the environment variables.');
            return null;
        }

        if (!isset($this->caller)) {
            Log::error('No caller set for authenticated ESI request.');
            return null;
        }

        return $this->handleApiRequest(function () use ($corpId) {
            return $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/members/titles", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    public function getCorporationMembersTracking()
    {
        $corpId = env('EVE_CORPORATION_ID');
        if (!$corpId) {
            Log::error('Corporation ID is not set in the environment variables.');
            return null;
        }

        if (!isset($this->caller)) {
            Log::error('No caller set for authenticated ESI request.');
            return null;
        }

        return $this->handleApiRequest(function () use ($corpId) {
            return $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/membertracking", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    public function getCorporationData($corpId): ?array
    {
        $data = $this->handleApiRequest(function () use ($corpId) {
            return $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/", [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
        });

        if ($data) {
            // Update or create based on corporation_id
            EveCorporation::updateOrCreate(
                ['corporation_id' => $corpId],
                [
                    'name' => $data['name'],
                    'ticker' => $data['ticker'],
                    'description' => $data['description'] ?? null,
                    'url' => $data['url'] ?? null,
                    'ceo_id' => $data['ceo_id'] ?? null,
                    'creator_id' => $data['creator_id'] ?? null,
                    'date_founded' => $data['date_founded'] ?? null,
                    'home_station_id' => $data['home_station_id'] ?? null,
                    'member_count' => $data['member_count'] ?? 0,
                    'tax_rate' => $data['tax_rate'] ?? 0.0,
                    'war_eligible' => $data['war_eligible'] ?? true,
                ]
            );
        }

        return $data;
    }

    public function getCharacterData(int $characterId): ?array
    {
        $data = $this->handleApiRequest(function () use ($characterId) {
            return $this->client->get("{$this->esiUrl}/latest/characters/{$characterId}/", [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
        });

        if ($data) {
            $character = EveCharacter::where('character_id', $characterId)->first();
            if ($character) {
                // Update the character's public data
                $character->update([
                    'public_data' => $data,
                    'corporation_id' => $data['corporation_id'] ?? null,
                ]);
            }
        }

        return $data;
    }

    public function verify()
    {
        if (!isset($this->caller)) {
            Log::error('No caller set for authenticated ESI request.');
            return null;
        }

        return $this->handleApiRequest(function () {
            return $this->client->get("{$this->esiUrl}/verify", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    public function getCorporationTitles()
    {
        $corpId = env('EVE_CORPORATION_ID');
        if (!$corpId) {
            Log::error('Corporation ID is not set in the environment variables.');
            return null;
        }

        if (!isset($this->caller)) {
            Log::error('No caller set for authenticated ESI request.');
            return null;
        }

        return $this->handleApiRequest(function () use ($corpId) {
            return $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/titles", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    public function getCorporationDirectors()
    {
        $corpId = env('EVE_CORPORATION_ID');
        if (!$corpId) {
            Log::error('Corporation ID is not set in the environment variables.');
            return null;
        }

        if (!isset($this->caller)) {
            Log::error('No caller set for authenticated ESI request.');
            return null;
        }

        $responseData = $this->handleApiRequest(function () use ($corpId) {
            return $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/roles", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });

        if (!$responseData) {
            return null;
        }

        $directors = [];
        // Loop through the roles and find the directors
        foreach ($responseData as $member) {
            // Check if $member['roles'] contain "Director" and if so, add it to the $directors array
            if (in_array('Director', $member['roles'])) {
                $directors[] = $member['character_id'];
            }
        }

        return $directors;
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
        if (!$character->is_valid) {
            return;
        }

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

    public function getNotifications(EveCharacter $character): ?array
    {
        if (!$character->is_valid) {
            return null;
        }

        return $this->handleApiRequest(function () use ($character) {
            return $this->client->get("{$this->esiUrl}/latest/characters/{$character->character_id}/notifications/", [
                'headers' => [
                    'Authorization' => "Bearer {$character->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    public function getMainStructures(): ?array
    {
        $ceo = $this->getMainCeo();
        if (!$ceo) {
            Log::error('No main CEO found for the corporation.');
            return null;
        }

        return $this->handleApiRequest(function () use ($ceo) {
            return $this->client->get("{$this->esiUrl}/latest/corporations/" . env('EVE_CORPORATION_ID') . "/structures/", [
                'headers' => [
                    'Authorization' => "Bearer {$ceo->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    public function getSkills(EveCharacter $character): ?array
    {
        if (!$character->is_valid) {
            return null;
        }

        return $this->handleApiRequest(function () use ($character) {
            return $this->client->get("{$this->esiUrl}/latest/characters/{$character->character_id}/skills/", [
                'headers' => [
                    'Authorization' => "Bearer {$character->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }
}
