<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class EveMetenoxFuelCalculator
{
    public const STRUCTURE_TYPE_ID = 81826;

    public const MAGMATIC_GAS_TYPE_ID = 81143;

    public const FUEL_BLOCKS_PER_HOUR = 5;

    public const MAGMATIC_GAS_PER_HOUR = 200;

    /**
     * Calculate the Magmatic Gas runway from corporation assets.
     *
     * A null asset list means ESI data was unavailable. An empty list means
     * the fuel bay was read successfully and contains no Magmatic Gas.
     *
     * @return array{quantity: int|null, hours_remaining: float|null, expires_at: Carbon|null}
     */
    public function calculateMagmaticGasRunway(int $structureId, ?array $assets, ?Carbon $now = null): array
    {
        if ($assets === null) {
            return [
                'quantity' => null,
                'hours_remaining' => null,
                'expires_at' => null,
            ];
        }

        $quantity = 0;

        foreach ($assets as $asset) {
            if ((int) ($asset['location_id'] ?? 0) !== $structureId) {
                continue;
            }

            if (($asset['location_flag'] ?? '') !== 'StructureFuel') {
                continue;
            }

            if ((int) ($asset['type_id'] ?? 0) !== self::MAGMATIC_GAS_TYPE_ID) {
                continue;
            }

            $quantity += (int) ($asset['quantity'] ?? 0);
        }

        $hoursRemaining = (float) $quantity / self::MAGMATIC_GAS_PER_HOUR;
        $expiresAt = ($now ?? Carbon::now())->copy()
            ->addSeconds((int) floor($hoursRemaining * 3600));

        return [
            'quantity' => $quantity,
            'hours_remaining' => $hoursRemaining,
            'expires_at' => $expiresAt,
        ];
    }
}
