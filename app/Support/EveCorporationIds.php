<?php

namespace App\Support;

final class EveCorporationIds
{
    public static function parse(?string $value): array
    {
        if (! $value) {
            return [];
        }

        $ids = [];

        foreach (explode(',', $value) as $candidate) {
            $candidate = trim($candidate);

            if (! ctype_digit($candidate) || (int) $candidate <= 0) {
                continue;
            }

            $ids[] = (int) $candidate;
        }

        return array_values(array_unique($ids));
    }

    public static function holdings(?string $value, ?int $mainCorporationId): array
    {
        return array_values(array_filter(
            self::parse($value),
            fn (int $corporationId): bool => $corporationId !== $mainCorporationId
        ));
    }
}
