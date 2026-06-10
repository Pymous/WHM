<?php

use App\Services\EvePosFuelCalculator;
use Symfony\Component\Yaml\Yaml;

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Temp file path used for all tests in this suite. */
function tmpYamlPath(): string
{
    return sys_get_temp_dir() . '/controlTowerResources_test.yaml';
}

/** Write data to the temp YAML file and return a calculator wired to it. */
function makeCalculator(array $data): EvePosFuelCalculator
{
    file_put_contents(tmpYamlPath(), Yaml::dump($data));
    return new EvePosFuelCalculator(tmpYamlPath());
}

afterEach(function () {
    $path = tmpYamlPath();
    if (file_exists($path)) {
        unlink($path);
    }
});

// ── getHourlyRequirements ────────────────────────────────────────────────────

test('returns unconditional purpose-1 resources only', function () {
    $calc = makeCalculator([
        99999 => [
            'resources' => [
                ['purpose' => 4, 'quantity' => 200, 'resourceTypeID' => 16275],   // strontium – ignored
                ['purpose' => 1, 'quantity' => 10,  'resourceTypeID' => 4247],    // fuel block – kept
                ['purpose' => 1, 'quantity' => 1,   'resourceTypeID' => 24592, 'factionID' => 500003, 'minSecurityLevel' => 0.45], // charter – skipped
            ],
        ],
    ]);

    $reqs = $calc->getHourlyRequirements(99999);

    expect($reqs)->toHaveCount(1)
        ->and($reqs[0]['resourceTypeID'])->toBe(4247)
        ->and($reqs[0]['quantity'])->toBe(10);
});

test('returns empty array for unknown tower type', function () {
    $calc = makeCalculator([]);

    expect($calc->getHourlyRequirements(99999))->toBeEmpty();
});

// ── calculateRunway ───────────────────────────────────────────────────────────

test('single fuel type: calculates correct hours remaining', function () {
    $calc   = makeCalculator([
        99999 => ['resources' => [['purpose' => 1, 'quantity' => 10, 'resourceTypeID' => 4247]]],
    ]);
    $result = $calc->calculateRunway(99999, [['type_id' => 4247, 'quantity' => 480]]);

    // 480 units at 10/h = 48 h
    expect($result['hours_remaining'])->toBe(48.0)
        ->and($result['limiting_fuel_type_id'])->toBe(4247)
        ->and($result['limiting_fuel_quantity'])->toBe(480)
        ->and($result['limiting_fuel_consumption_per_hour'])->toBe(10)
        ->and($result['fuel_expires'])->not->toBeNull();
});

test('fuel_expires is approximately 48 hours from now', function () {
    $calc   = makeCalculator([
        99999 => ['resources' => [['purpose' => 1, 'quantity' => 10, 'resourceTypeID' => 4247]]],
    ]);
    $result = $calc->calculateRunway(99999, [['type_id' => 4247, 'quantity' => 480]]);

    $expected = (new DateTimeImmutable())->modify('+48 hours');
    $diff     = abs($result['fuel_expires']->getTimestamp() - $expected->getTimestamp());

    expect($diff)->toBeLessThan(5); // within 5-second tolerance
});

test('minimum runway wins when multiple fuel types required', function () {
    $calc   = makeCalculator([
        99999 => [
            'resources' => [
                ['purpose' => 1, 'quantity' => 10, 'resourceTypeID' => 4247], // 480/10 = 48 h
                ['purpose' => 1, 'quantity' => 5,  'resourceTypeID' => 4312], // 50/5  = 10 h ← limiting
            ],
        ],
    ]);
    $result = $calc->calculateRunway(99999, [
        ['type_id' => 4247, 'quantity' => 480],
        ['type_id' => 4312, 'quantity' => 50],
    ]);

    expect($result['hours_remaining'])->toBe(10.0)
        ->and($result['limiting_fuel_type_id'])->toBe(4312)
        ->and($result['limiting_fuel_quantity'])->toBe(50);
});

test('purpose-4 (strontium) is never counted toward runway', function () {
    $calc   = makeCalculator([
        99999 => [
            'resources' => [
                ['purpose' => 4, 'quantity' => 200, 'resourceTypeID' => 16275],
                ['purpose' => 1, 'quantity' => 10,  'resourceTypeID' => 4247],
            ],
        ],
    ]);
    $result = $calc->calculateRunway(99999, [
        ['type_id' => 16275, 'quantity' => 9999],
        ['type_id' => 4247,  'quantity' => 100],
    ]);

    expect($result['limiting_fuel_type_id'])->toBe(4247)
        ->and($result['hours_remaining'])->toBe(10.0);
});

test('returns null fuel_expires when no SDE data for tower', function () {
    $calc   = makeCalculator([]);
    $result = $calc->calculateRunway(99999, []);

    expect($result['fuel_expires'])->toBeNull()
        ->and($result['hours_remaining'])->toBeNull();
});

test('zero stock for a required fuel type gives zero hours (POS out of fuel)', function () {
    $calc   = makeCalculator([
        99999 => ['resources' => [['purpose' => 1, 'quantity' => 10, 'resourceTypeID' => 4247]]],
    ]);
    $result = $calc->calculateRunway(99999, [['type_id' => 4247, 'quantity' => 0]]);

    expect($result['hours_remaining'])->toBe(0.0);
});

test('missing fuel type from stock defaults to zero quantity (POS effectively out of fuel)', function () {
    $calc   = makeCalculator([
        99999 => ['resources' => [['purpose' => 1, 'quantity' => 10, 'resourceTypeID' => 4247]]],
    ]);
    // Empty fuel bay – ESI returned no fuels for this POS.
    $result = $calc->calculateRunway(99999, []);

    expect($result['hours_remaining'])->toBe(0.0);
});
