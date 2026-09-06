<?php

namespace App\Services;

use App\Models\EveCharacter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class EveCorporationAccessResolver
{
    public function __construct(private readonly EveOnlineProvider $provider) {}

    public function director(int $corporationId, array $requiredScopes = []): ?EveCharacter
    {
        [$candidates, $ceoId] = $this->candidates($corporationId, $requiredScopes);

        foreach ($candidates as $candidate) {
            if ((int) $candidate->character_id === $ceoId) {
                return $candidate;
            }

            if (in_array('Director', $this->rolesFor($candidate) ?? [], true)) {
                return $candidate;
            }
        }

        return null;
    }

    public function structureManager(int $corporationId, array $requiredScopes = []): ?EveCharacter
    {
        [$candidates, $ceoId] = $this->candidates($corporationId, $requiredScopes);

        foreach ($candidates as $candidate) {
            if ((int) $candidate->character_id === $ceoId) {
                return $candidate;
            }

            $roles = $this->rolesFor($candidate) ?? [];

            if (array_intersect(['Director', 'Station_Manager'], $roles)) {
                return $candidate;
            }
        }

        return null;
    }

    public function notificationReader(int $corporationId): ?EveCharacter
    {
        return $this->structureManager($corporationId, [
            'esi-characters.read_notifications.v1',
        ]);
    }

    /**
     * @return array{Collection<int, EveCharacter>, int|null}
     */
    private function candidates(int $corporationId, array $requiredScopes): array
    {
        $corporation = $this->corporationData($corporationId);
        $ceoId = isset($corporation['ceo_id']) ? (int) $corporation['ceo_id'] : null;

        $candidates = EveCharacter::query()
            ->where('corporation_id', $corporationId)
            ->where('is_valid', true)
            ->get()
            ->filter(fn (EveCharacter $character): bool => $this->hasScopes($character, $requiredScopes))
            ->sortByDesc(fn (EveCharacter $character): bool => (int) $character->character_id === $ceoId)
            ->values();

        return [$candidates, $ceoId];
    }

    private function hasScopes(EveCharacter $character, array $requiredScopes): bool
    {
        if ($requiredScopes === []) {
            return true;
        }

        $actualScopes = preg_split('/[\s,]+/', trim((string) $character->esi_scopes)) ?: [];

        return array_diff($requiredScopes, $actualScopes) === [];
    }

    private function corporationData(int $corporationId): ?array
    {
        $cacheKey = "eve:corporation:{$corporationId}:public-data";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $corporation = $this->provider->getCorporationData($corporationId);

        if ($corporation !== null) {
            Cache::put($cacheKey, $corporation, now()->addMinutes(55));
        }

        return $corporation;
    }

    private function rolesFor(EveCharacter $character): ?array
    {
        $cacheKey = "eve:character:{$character->character_id}:corporation-roles";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->provider->getCharacterCorporationRoles($character);

        if ($response === null) {
            return null;
        }

        $roles = $response['roles'] ?? [];
        Cache::put($cacheKey, $roles, now()->addMinutes(55));

        return $roles;
    }
}
