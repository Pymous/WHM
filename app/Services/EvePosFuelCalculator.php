<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Symfony\Component\Yaml\Yaml;

class EvePosFuelCalculator
{
    /**
     * Hourly fuel resource purpose code in the SDE.
     */
    private const PURPOSE_FUEL = 1;

    /**
     * Loaded controlTowerResources data, keyed by tower type_id.
     *
     * @var array<int, list<array{resourceTypeID: int, quantity: int, purpose: int}>>|null
     */
    private ?array $resources = null;

    /** Absolute path to controlTowerResources.yaml. */
    private string $yamlPath;

    public function __construct(?string $yamlPath = null)
    {
        $this->yamlPath = $yamlPath ?? storage_path('sde/fsd/controlTowerResources.yaml');
    }

    /**
     * Load controlTowerResources.yaml once and cache.
     */
    private function loadResources(): void
    {
        if ($this->resources !== null) {
            return;
        }

        if (!file_exists($this->yamlPath)) {
            error_log('EvePosFuelCalculator: controlTowerResources.yaml not found at ' . $this->yamlPath);
            $this->resources = [];
            return;
        }

        $raw = Yaml::parseFile($this->yamlPath);
        $this->resources = is_array($raw) ? $raw : [];
    }

    /**
     * Get hourly fuel requirements for a given tower type_id.
     * Returns only purpose: 1 entries without factionID (i.e. unconditional fuel blocks).
     *
     * @return list<array{resourceTypeID: int, quantity: int}>
     */
    public function getHourlyRequirements(int $typeId): array
    {
        $this->loadResources();

        $entry = $this->resources[$typeId] ?? null;
        if (!$entry || empty($entry['resources'])) {
            error_log("EvePosFuelCalculator: no resource data for tower type_id={$typeId}");
            return [];
        }

        $requirements = [];
        foreach ($entry['resources'] as $resource) {
            if (($resource['purpose'] ?? 0) !== self::PURPOSE_FUEL) {
                continue;
            }
            // Skip faction-specific charter requirements (only relevant in highsec).
            // For wormhole / null / low we only care about the unconditional fuel block.
            if (isset($resource['factionID'])) {
                continue;
            }
            $requirements[] = [
                'resourceTypeID' => (int) $resource['resourceTypeID'],
                'quantity'       => (int) $resource['quantity'],
            ];
        }

        return $requirements;
    }

    /**
     * Calculate the limiting fuel runway (in hours) for an online POS.
     *
     * @param  int                                         $towerTypeId   ESI type_id of the tower.
     * @param  list<array{type_id: int, quantity: int}>   $fuelBayStock  Returned by ESI starbase detail.
     * @return array{
     *     hours_remaining: float|null,
     *     fuel_expires: \Illuminate\Support\Carbon|null,
     *     limiting_fuel_type_id: int|null,
     *     limiting_fuel_quantity: int|null,
     *     limiting_fuel_consumption_per_hour: int|null,
     * }
     */
    public function calculateRunway(int $towerTypeId, array $fuelBayStock): array
    {
        $hourlyRequirements = $this->getHourlyRequirements($towerTypeId);

        $empty = [
            'hours_remaining'                   => null,
            'fuel_expires'                      => null,
            'limiting_fuel_type_id'             => null,
            'limiting_fuel_quantity'            => null,
            'limiting_fuel_consumption_per_hour' => null,
        ];

        if (empty($hourlyRequirements)) {
            // Log here is intentionally omitted – caller already warned in getHourlyRequirements.
            return $empty;
        }

        // Index stock by type_id for O(1) lookup.
        $stockByTypeId = [];
        foreach ($fuelBayStock as $item) {
            $stockByTypeId[(int) $item['type_id']] = (int) $item['quantity'];
        }

        $minHours          = null;
        $limitingTypeId    = null;
        $limitingQuantity  = null;
        $limitingRate      = null;

        foreach ($hourlyRequirements as $req) {
            $typeId   = $req['resourceTypeID'];
            $rate     = $req['quantity'];
            $stock    = $stockByTypeId[$typeId] ?? 0;

            $hours = $rate > 0 ? ((float) $stock / (float) $rate) : PHP_FLOAT_MAX;

            if ($minHours === null || $hours < $minHours) {
                $minHours         = $hours;
                $limitingTypeId   = $typeId;
                $limitingQuantity = $stock;
                $limitingRate     = $rate;
            }
        }

        return [
            'hours_remaining'                   => $minHours,
            'fuel_expires'                      => $minHours !== null ? Carbon::now()->addSeconds((int) floor($minHours * 3600)) : null,
            'limiting_fuel_type_id'             => $limitingTypeId,
            'limiting_fuel_quantity'            => $limitingQuantity,
            'limiting_fuel_consumption_per_hour' => $limitingRate,
        ];
    }
}
