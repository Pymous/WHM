<?php

use App\Services\EveMetenoxFuelCalculator;
use Illuminate\Support\Carbon;

test('calculates Magmatic Gas expiry from the matching structure fuel bay', function () {
    $calculator = new EveMetenoxFuelCalculator;
    $now = Carbon::parse('2026-06-10 12:00:00 UTC');

    $result = $calculator->calculateMagmaticGasRunway(12345, [
        [
            'location_id' => 12345,
            'location_flag' => 'StructureFuel',
            'type_id' => EveMetenoxFuelCalculator::MAGMATIC_GAS_TYPE_ID,
            'quantity' => 4800,
        ],
        [
            'location_id' => 99999,
            'location_flag' => 'StructureFuel',
            'type_id' => EveMetenoxFuelCalculator::MAGMATIC_GAS_TYPE_ID,
            'quantity' => 999999,
        ],
    ], $now);

    expect($result['quantity'])->toBe(4800)
        ->and($result['hours_remaining'])->toBe(24.0)
        ->and($result['expires_at']->toIso8601String())->toBe('2026-06-11T12:00:00+00:00');
});

test('returns an immediate expiry when the structure fuel bay has no Magmatic Gas', function () {
    $calculator = new EveMetenoxFuelCalculator;
    $now = Carbon::parse('2026-06-10 12:00:00 UTC');

    $result = $calculator->calculateMagmaticGasRunway(12345, [], $now);

    expect($result['quantity'])->toBe(0)
        ->and($result['hours_remaining'])->toBe(0.0)
        ->and($result['expires_at']->equalTo($now))->toBeTrue();
});

test('returns unknown runway when corporation assets are unavailable', function () {
    $calculator = new EveMetenoxFuelCalculator;

    $result = $calculator->calculateMagmaticGasRunway(12345, null);

    expect($result['quantity'])->toBeNull()
        ->and($result['hours_remaining'])->toBeNull()
        ->and($result['expires_at'])->toBeNull();
});
