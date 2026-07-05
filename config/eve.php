<?php

use App\Support\EveCorporationIds;

$mainCorporationId = EveCorporationIds::parse((string) env('EVE_CORPORATION_ID'))[0] ?? null;
$holdingCorporationIds = EveCorporationIds::holdings(
    (string) env('EVE_HOLDING_CORPORATION_IDS', ''),
    $mainCorporationId
);

return [
    'corporations' => [
        'main' => $mainCorporationId,
        'holding' => $holdingCorporationIds,
        'operational' => array_values(array_filter([
            $mainCorporationId,
            ...$holdingCorporationIds,
        ])),
    ],
];
