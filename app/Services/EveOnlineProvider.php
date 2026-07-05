<?php

namespace App\Services;

use App\Models\EveCharacter;
use App\Models\EveCorporation;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        $this->client = new Client;
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
                    'Authorization' => 'Basic '.base64_encode("{$this->clientId}:{$this->clientSecret}"),
                ],
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                ],
            ]);

            $tokenData = json_decode($response->getBody()->getContents(), true);
            $characterData = $this->verifyToken($tokenData['access_token']);

            if (! $characterData) {
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
        if (! $user) {
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
                'has_required_scopes' => $this->hasRequiredScopes($scopes),
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
                    'Authorization' => 'Basic '.base64_encode("{$this->clientId}:{$this->clientSecret}"),
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
                'is_valid' => true,
            ]);

            return true;
        } catch (GuzzleException $e) {
            $oauthError = $this->getOAuthError($e);

            if (in_array($oauthError, ['invalid_grant', 'invalid_token'], true)) {
                $character->update(['is_valid' => false]);

                Log::warning('EVE Online refresh token was permanently rejected', [
                    'character_id' => $character->character_id,
                    'oauth_error' => $oauthError,
                ]);

                return false;
            }

            Log::error('EVE Online SSO token refresh failed', [
                'error' => $e->getMessage(),
                'character_id' => $character->character_id,
                'status_code' => $e instanceof RequestException && $e->hasResponse()
                    ? $e->getResponse()->getStatusCode()
                    : null,
                'oauth_error' => $oauthError,
            ]);

            return false;
        }
    }

    /**
     * Determine whether the character granted every scope currently required by the app.
     */
    public function hasRequiredScopes(?string $actualScopes): bool
    {
        $normalize = static function (?string $scopes): array {
            if (! $scopes) {
                return [];
            }

            return array_values(array_unique(array_filter(
                preg_split('/[\s,]+/', trim($scopes)) ?: []
            )));
        };

        $expected = $normalize(config('services.eveonline.scopes'));
        $actual = $normalize($actualScopes);

        return array_diff($expected, $actual) === [];
    }

    protected function getOAuthError(GuzzleException $exception): ?string
    {
        if (! $exception instanceof RequestException || ! $exception->hasResponse()) {
            return null;
        }

        $data = json_decode((string) $exception->getResponse()->getBody(), true);

        return is_array($data) && is_string($data['error'] ?? null)
            ? $data['error']
            : null;
    }

    /**
     * Helper method to handle API responses and catch specific error codes.
     */
    protected function handleApiRequest(
        callable $requestFunction,
        ?EveCharacter $character = null,
        bool $retryOnUnauthorized = true
    ): ?array {
        try {
            $response = $requestFunction();

            return json_decode($response->getBody()->getContents(), true);
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if ($statusCode === 401 && $character && $retryOnUnauthorized) {
                if ($this->refreshToken($character)) {
                    return $this->handleApiRequest($requestFunction, $character, false);
                }
            }

            if ($statusCode === 404) {
                return null;
            }

            Log::error('EVE ESI API request failed', [
                'status_code' => $statusCode,
                'error' => $e->getMessage(),
                'character_id' => $character?->character_id,
            ]);

            return null;
        } catch (GuzzleException $e) {
            Log::error('EVE ESI API request failed', [
                'error' => $e->getMessage(),
                'character_id' => $character?->character_id,
            ]);

            return null;
        }
    }

    protected function handleAuthenticatedApiRequest(
        EveCharacter $character,
        callable $requestFunction
    ): ?array {
        if (! $character->is_valid) {
            return null;
        }

        if ($character->isTokenExpired() && ! $this->refreshToken($character)) {
            return null;
        }

        return $this->handleApiRequest($requestFunction, $character);
    }

    /**
     * Make an authenticated request to the ESI API.
     */
    public function request(EveCharacter $character, string $method, string $endpoint, array $options = []): ?array
    {
        return $this->handleAuthenticatedApiRequest($character, function () use ($method, $endpoint, $options, $character) {
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

    public function getCorporationMembersTitles(?int $corpId = null)
    {
        $corpId ??= config('eve.corporations.main');
        if (! $corpId) {
            Log::error('Main corporation ID is not configured.');

            return null;
        }

        if (! isset($this->caller)) {
            Log::error('No caller set for authenticated ESI request.');

            return null;
        }

        return $this->handleAuthenticatedApiRequest($this->caller, function () use ($corpId) {
            return $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/members/titles", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    public function getCorporationMembersTracking(?int $corpId = null)
    {
        $corpId ??= config('eve.corporations.main');
        if (! $corpId) {
            Log::error('Main corporation ID is not configured.');

            return null;
        }

        if (! isset($this->caller)) {
            Log::error('No caller set for authenticated ESI request.');

            return null;
        }

        return $this->handleAuthenticatedApiRequest($this->caller, function () use ($corpId) {
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
                    'alliance_id' => $data['alliance_id'] ?? null,
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
                    'name' => $data['name'],
                    'public_data' => $data,
                    'corporation_id' => $data['corporation_id'] ?? null,
                ]);
            }

            return $data;
        }

        return null;
    }

    public function getCharactersAffiliation(array $characterIds): ?array
    {
        if (empty($characterIds)) {
            return null;
        }

        return $this->handleApiRequest(function () use ($characterIds) {
            return $this->client->post("{$this->esiUrl}/latest/characters/affiliation/", [
                'json' => $characterIds,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    public function verify()
    {
        if (! isset($this->caller)) {
            Log::error('No caller set for authenticated ESI request.');

            return null;
        }

        return $this->handleAuthenticatedApiRequest($this->caller, function () {
            return $this->client->get("{$this->baseUrl}/oauth/verify", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    public function getCorporationTitles(?int $corpId = null)
    {
        $corpId ??= config('eve.corporations.main');
        if (! $corpId) {
            Log::error('Main corporation ID is not configured.');

            return null;
        }

        if (! isset($this->caller)) {
            Log::error('No caller set for authenticated ESI request.');

            return null;
        }

        return $this->handleAuthenticatedApiRequest($this->caller, function () use ($corpId) {
            return $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/titles", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    public function getCorporationDirectors(?int $corpId = null)
    {
        $corpId ??= config('eve.corporations.main');
        if (! $corpId) {
            Log::error('Main corporation ID is not configured.');

            return null;
        }

        if (! isset($this->caller)) {
            Log::error('No caller set for authenticated ESI request.');

            return null;
        }

        $responseData = $this->handleAuthenticatedApiRequest($this->caller, function () use ($corpId) {
            return $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/roles", [
                'headers' => [
                    'Authorization' => "Bearer {$this->caller->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });

        if (! $responseData) {
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

    public function getCorporationCeo(int $corpId): ?EveCharacter
    {
        $corporation = $this->getCorporation($corpId);
        if (! $corporation) {
            Log::error('Failed to retrieve corporation details.', ['corporation_id' => $corpId]);

            return null;
        }

        // Check if we have a EveCharacter in the database associated with the CEO $corporation['ceo_id'], and get his user
        $ceoCharacter = EveCharacter::where('character_id', $corporation['ceo_id'])->first();
        if (! $ceoCharacter) {
            Log::error('CEO character not found in the database.', [
                'corporation_id' => $corpId,
                'ceo_id' => $corporation['ceo_id'],
            ]);

            return null;
        }

        return $ceoCharacter ?? null;
    }

    public function getMainCeo(): ?EveCharacter
    {
        $corpId = config('eve.corporations.main');

        if (! $corpId) {
            Log::error('Main corporation ID is not configured.');

            return null;
        }

        return $this->getCorporationCeo($corpId);
    }

    public function setCaller(EveCharacter $character): void
    {
        if (! $character->is_valid) {
            return;
        }

        $this->caller = $character;
        // Check if the caller token is expired, if yes, refresh it
        if ($this->caller->isTokenExpired()) {
            if (! $this->refreshToken($this->caller)) {
                Log::error('Failed to refresh token for caller character.', [
                    'character_id' => $this->caller->character_id,
                ]);
            }
        }
    }

    public function getNotifications(EveCharacter $character): ?array
    {
        return $this->handleAuthenticatedApiRequest($character, function () use ($character) {
            return $this->client->get("{$this->esiUrl}/latest/characters/{$character->character_id}/notifications/", [
                'headers' => [
                    'Authorization' => "Bearer {$character->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    public function getCharacterCorporationRoles(EveCharacter $character): ?array
    {
        return $this->handleAuthenticatedApiRequest($character, function () use ($character) {
            return $this->client->get("{$this->esiUrl}/latest/characters/{$character->character_id}/roles/", [
                'headers' => [
                    'Authorization' => "Bearer {$character->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    /**
     * Resolve a list of EVE IDs (type IDs, solar system IDs, etc.) to names
     * using the public ESI /universe/names/ endpoint.
     *
     * NOTE: celestial IDs (planets, moons — 40000000+) are NOT supported by this
     * endpoint. Use resolveMoonName() for moon IDs.
     *
     * Returns a map of [ id => name ]. Unknown IDs are silently omitted.
     */
    public function resolveNames(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        // ESI accepts at most 1000 IDs per request.
        $chunks = array_chunk(array_values(array_unique($ids)), 1000);
        $map = [];

        foreach ($chunks as $chunk) {
            $result = $this->handleApiRequest(function () use ($chunk) {
                return $this->client->post("{$this->esiUrl}/latest/universe/names/", [
                    'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
                    'json' => $chunk,
                ]);
            });

            if (is_array($result)) {
                foreach ($result as $entry) {
                    $map[(int) $entry['id']] = $entry['name'];
                }
            }
        }

        return $map;
    }

    /**
     * Resolve a moon ID to its name via GET /universe/moons/{moon_id}/.
     * Returns null if the moon is unknown or the request fails.
     */
    public function resolveMoonName(int $moonId): ?string
    {
        $result = $this->handleApiRequest(function () use ($moonId) {
            return $this->client->get("{$this->esiUrl}/latest/universe/moons/{$moonId}/", [
                'headers' => ['Accept' => 'application/json'],
            ]);
        });

        return $result['name'] ?? null;
    }

    public function getCorporationStructures(int $corpId, EveCharacter $actor): ?array
    {
        return $this->handleAuthenticatedApiRequest($actor, function () use ($actor, $corpId) {
            return $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/structures/", [
                'headers' => [
                    'Authorization' => "Bearer {$actor->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }

    /**
     * Fetch all corporation assets, including structure fuel bays.
     */
    public function getCorporationAssets(int $corpId, EveCharacter $actor): ?array
    {
        $page = 1;
        $results = [];

        do {
            $pageData = $this->handleAuthenticatedApiRequest($actor, function () use ($actor, $corpId, $page) {
                return $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/assets/", [
                    'headers' => [
                        'Authorization' => "Bearer {$actor->esi_access_token}",
                        'Accept' => 'application/json',
                    ],
                    'query' => ['page' => $page],
                ]);
            });

            if ($pageData === null) {
                return null;
            }

            $results = array_merge($results, $pageData);
            $page++;
        } while (count($pageData) >= 1000);

        return $results;
    }

    /**
     * Fetch all corporation POS/starbases across all pages.
     * Returns an empty array (not null) when none exist.
     */
    public function getCorporationStarbases(int $corpId, EveCharacter $actor): ?array
    {
        $page = 1;
        $results = [];

        do {
            $pageData = $this->handleAuthenticatedApiRequest($actor, function () use ($actor, $corpId, $page) {
                return $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/starbases/", [
                    'headers' => [
                        'Authorization' => "Bearer {$actor->esi_access_token}",
                        'Accept' => 'application/json',
                    ],
                    'query' => ['page' => $page],
                ]);
            });

            if ($pageData === null) {
                return null;
            }

            $results = array_merge($results, $pageData);
            $page++;
        } while (count($pageData) >= 1000);

        return $results;
    }

    /**
     * Fetch detail for a single starbase, including its fuel bay contents.
     */
    public function getCorporationStarbaseDetail(
        int $corpId,
        EveCharacter $actor,
        int $starbaseId,
        int $systemId
    ): ?array {
        return $this->handleAuthenticatedApiRequest($actor, function () use ($actor, $corpId, $starbaseId, $systemId) {
            return $this->client->get("{$this->esiUrl}/latest/corporations/{$corpId}/starbases/{$starbaseId}/", [
                'headers' => [
                    'Authorization' => "Bearer {$actor->esi_access_token}",
                    'Accept' => 'application/json',
                ],
                'query' => ['system_id' => $systemId],
            ]);
        });
    }

    public function getSkills(EveCharacter $character): ?array
    {
        return $this->handleAuthenticatedApiRequest($character, function () use ($character) {
            return $this->client->get("{$this->esiUrl}/latest/characters/{$character->character_id}/skills/", [
                'headers' => [
                    'Authorization' => "Bearer {$character->esi_access_token}",
                    'Accept' => 'application/json',
                ],
            ]);
        });
    }
}
