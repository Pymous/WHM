<?php

use App\Models\EveCharacter;
use App\Models\EvePiTaxEntry;
use App\Models\User;
use App\Services\EveCorporationAccessResolver;
use App\Services\EveOnlineProvider;
use App\Services\EvePiTaxAnalyticsService;
use App\Services\EvePiTaxSyncService;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

test('the PI revenue dashboard is restricted to administrators', function () {
    $this->withoutVite();
    config(['eve.corporations.main' => 98748326]);
    $admin = User::create(['name' => 'PI Admin', 'is_admin' => true]);
    $member = User::create(['name' => 'PI Member']);

    $this->actingAs($admin)
        ->get(route('admin.pi-revenue'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/PiRevenue')
            ->has('initialData.stats')
            ->where('initialData.range', '7d'));

    $this->actingAs($member)
        ->get(route('admin.pi-revenue'))
        ->assertRedirect(route('dashboard'));
});

test('PI analytics follow the selected range and calculate its breakdown', function () {
    $corporationId = 98748326;

    EvePiTaxEntry::create([
        'corporation_id' => $corporationId,
        'journal_id' => 1001,
        'wallet_division' => 1,
        'ref_type' => 'planetary_export_tax',
        'amount' => 750000,
        'occurred_at' => now()->subDays(2),
        'payer_id' => 2001,
        'payer_name' => 'Exporter One',
        'planet_id' => 40000001,
        'planet_name' => 'Test III',
    ]);
    EvePiTaxEntry::create([
        'corporation_id' => $corporationId,
        'journal_id' => 1002,
        'wallet_division' => 1,
        'ref_type' => 'planetary_import_tax',
        'amount' => 250000,
        'occurred_at' => now()->subDay(),
        'payer_id' => 2002,
        'payer_name' => 'Importer Two',
        'planet_id' => 40000001,
        'planet_name' => 'Test III',
    ]);
    EvePiTaxEntry::create([
        'corporation_id' => $corporationId,
        'journal_id' => 1003,
        'wallet_division' => 1,
        'ref_type' => 'planetary_export_tax',
        'amount' => 9000000,
        'occurred_at' => now()->subMonths(4),
        'payer_id' => 2003,
        'payer_name' => 'Old Exporter',
    ]);

    $result = app(EvePiTaxAnalyticsService::class)->analyze($corporationId, '7d');

    expect($result['stats'])
        ->total_revenue->toBe(1000000.0)
        ->average_daily_revenue->toBe(142857.14)
        ->entries->toBe(2)
        ->active_taxpayers->toBe(2)
        ->export_revenue->toBe(750000.0)
        ->import_revenue->toBe(250000.0)
        ->export_share->toBe(75.0)
        ->and($result['taxpayers'][0]['name'])->toBe('Exporter One')
        ->and($result['planets'][0]['amount'])->toBe(1000000.0)
        ->and($result['series']['granularity'])->toBe('day');
});

test('PI synchronization archives journal entries without duplicates', function () {
    Cache::flush();
    $corporationId = 98748326;
    $user = User::create(['name' => 'Wallet Director']);
    $actor = EveCharacter::create([
        'user_id' => $user->id,
        'character_id' => '2119000100',
        'name' => 'Wallet Director',
        'corporation_id' => $corporationId,
        'is_valid' => true,
        'has_required_scopes' => true,
        'esi_access_token' => 'access-token',
        'esi_refresh_token' => 'refresh-token',
        'esi_expires_at' => now()->addHour(),
    ]);

    $entries = [
        [
            'id' => 7001,
            'amount' => 750000,
            'balance' => 1000000,
            'date' => now()->subHour()->toIso8601String(),
            'ref_type' => 'planetary_export_tax',
            'first_party_id' => 3001,
            'second_party_id' => $corporationId,
            'context_id' => 40000001,
            'context_id_type' => 'planet_id',
        ],
        [
            'id' => 7002,
            'amount' => 250000,
            'balance' => 1250000,
            'date' => now()->subMinutes(30)->toIso8601String(),
            'ref_type' => 'planetary_import_tax',
            'first_party_id' => 3001,
            'second_party_id' => $corporationId,
            'context_id' => 40000001,
            'context_id_type' => 'planet_id',
        ],
        [
            'id' => 7003,
            'amount' => 100,
            'date' => now()->toIso8601String(),
            'ref_type' => 'player_donation',
        ],
    ];

    $access = Mockery::mock(EveCorporationAccessResolver::class);
    $access->shouldReceive('director')->with($corporationId, [
        'esi-wallet.read_corporation_wallets.v1',
    ])->twice()->andReturn($actor);

    $provider = Mockery::mock(EveOnlineProvider::class);
    $provider->shouldReceive('getCorporationWalletJournalPage')
        ->times(14)
        ->andReturnUsing(fn (int $corp, int $division, EveCharacter $character, int $page): array => [
            'entries' => $division === 1 ? $entries : [],
            'pages' => 1,
        ]);
    $provider->shouldReceive('resolveNames')->twice()->andReturn([3001 => 'PI Pilot']);
    $provider->shouldReceive('resolvePlanetName')->once()->with(40000001)->andReturn('Test III');

    $sync = new EvePiTaxSyncService($provider, $access);
    $first = $sync->sync($corporationId);
    $second = $sync->sync($corporationId);

    expect($first['stored'])->toBe(2)
        ->and($second['stored'])->toBe(2)
        ->and(EvePiTaxEntry::count())->toBe(2)
        ->and(EvePiTaxEntry::where('ref_type', 'player_donation')->exists())->toBeFalse()
        ->and(EvePiTaxEntry::firstWhere('journal_id', 7001)->payer_name)->toBe('PI Pilot')
        ->and(EvePiTaxEntry::firstWhere('journal_id', 7001)->planet_name)->toBe('Test III');
});

test('an admin can request a custom PI revenue range', function () {
    config(['eve.corporations.main' => 98748326]);
    $admin = User::create(['name' => 'Custom Range Admin', 'is_admin' => true]);

    EvePiTaxEntry::create([
        'corporation_id' => 98748326,
        'journal_id' => 8001,
        'wallet_division' => 1,
        'ref_type' => 'planetary_export_tax',
        'amount' => 123456,
        'occurred_at' => '2026-08-10 12:00:00',
        'payer_id' => 4001,
    ]);

    $this->actingAs($admin)
        ->getJson(route('admin.pi-revenue.data', [
            'range' => 'custom',
            'start' => '2026-08-10',
            'end' => '2026-08-10',
        ]))
        ->assertOk()
        ->assertJsonPath('stats.total_revenue', 123456)
        ->assertJsonPath('stats.average_daily_revenue', 123456)
        ->assertJsonPath('stats.entries', 1);
});
