<?php

use App\Models\EveCharacter;
use App\Models\EveNotification;
use App\Models\User;
use App\Services\EveCorporationAccessResolver;
use App\Services\EveOnlineProvider;
use Illuminate\Support\Facades\Artisan;

function makeOperationalCharacter(int $corporationId, int $characterId): EveCharacter
{
    $user = User::create(['name' => "Operational User {$characterId}"]);

    return EveCharacter::create([
        'user_id' => $user->id,
        'character_id' => (string) $characterId,
        'name' => "Operational Character {$characterId}",
        'corporation_id' => $corporationId,
        'is_valid' => true,
        'has_required_scopes' => true,
        'esi_access_token' => 'access-token',
        'esi_refresh_token' => 'refresh-token',
        'esi_expires_at' => now()->addHour(),
    ]);
}

test('the structures summary groups all operational corporations', function () {
    config(['eve.corporations.operational' => [98748326, 98760472]]);
    $mainActor = makeOperationalCharacter(98748326, 2119000001);
    $holdingActor = makeOperationalCharacter(98760472, 2119000002);

    $provider = Mockery::mock(EveOnlineProvider::class);
    $provider->shouldReceive('getCorporationData')
        ->with(98748326)
        ->once()
        ->andReturn(['name' => 'Main Corp', 'ticker' => 'MAIN']);
    $provider->shouldReceive('getCorporationData')
        ->with(98760472)
        ->once()
        ->andReturn(['name' => 'Repair Center Distribution', 'ticker' => 'F0RED']);
    $provider->shouldReceive('getCorporationStructures')->twice()->andReturn([]);
    $provider->shouldReceive('getCorporationStarbases')->twice()->andReturn([]);

    $access = Mockery::mock(EveCorporationAccessResolver::class);
    $access->shouldReceive('director')->with(98748326)->once()->andReturn($mainActor);
    $access->shouldReceive('director')->with(98760472)->once()->andReturn($holdingActor);
    $access->shouldNotReceive('structureManager');

    $this->app->instance(EveOnlineProvider::class, $provider);
    $this->app->instance(EveCorporationAccessResolver::class, $access);

    $exitCode = Artisan::call('eve:discord:structures-summary', ['--debug' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Main Corp [MAIN]')
        ->and($output)->toContain('Repair Center Distribution [F0RED]');
});

test('notification collection polls one selected character per operational corporation', function () {
    config(['eve.corporations.operational' => [98748326, 98760472]]);
    $mainActor = makeOperationalCharacter(98748326, 2119000011);
    $holdingActor = makeOperationalCharacter(98760472, 2119000012);

    $access = Mockery::mock(EveCorporationAccessResolver::class);
    $access->shouldReceive('notificationReader')->with(98748326)->once()->andReturn($mainActor);
    $access->shouldReceive('notificationReader')->with(98760472)->once()->andReturn($holdingActor);

    $provider = Mockery::mock(EveOnlineProvider::class);
    $provider->shouldReceive('getNotifications')
        ->with(Mockery::on(fn (EveCharacter $character): bool => $character->is($mainActor)))
        ->once()
        ->andReturn([[
            'notification_id' => 9001,
            'type' => 'StructureUnderAttack',
            'sender_id' => 1001,
            'sender_type' => 'corporation',
            'timestamp' => now()->toIso8601String(),
            'text' => 'structureID: 123',
            'is_read' => false,
        ]]);
    $provider->shouldReceive('getNotifications')
        ->with(Mockery::on(fn (EveCharacter $character): bool => $character->is($holdingActor)))
        ->once()
        ->andReturn([[
            'notification_id' => 9002,
            'type' => 'StructureFuelAlert',
            'sender_id' => 1002,
            'sender_type' => 'corporation',
            'timestamp' => now()->toIso8601String(),
            'text' => 'structureID: 456',
            'is_read' => false,
        ]]);

    $this->app->instance(EveOnlineProvider::class, $provider);
    $this->app->instance(EveCorporationAccessResolver::class, $access);

    $this->artisan('eve:notifications:get')->assertSuccessful();

    expect(EveNotification::find(9001)->corporation_id)->toBe(98748326)
        ->and(EveNotification::find(9002)->corporation_id)->toBe(98760472)
        ->and(EveNotification::count())->toBe(2);
});

test('holding corporation characters do not receive main corporation membership', function () {
    config([
        'eve.corporations.main' => 98748326,
        'eve.corporations.operational' => [98748326, 98760472],
    ]);
    $mainCharacter = makeOperationalCharacter(98748326, 2119000021);
    $holdingCharacter = makeOperationalCharacter(98760472, 2119000022);

    $provider = Mockery::mock(EveOnlineProvider::class);
    $provider->shouldReceive('getCharacterData')->twice()->andReturn(['name' => 'Updated']);
    $provider->shouldReceive('getCharactersAffiliation')
        ->once()
        ->andReturn([
            ['character_id' => (int) $mainCharacter->character_id, 'corporation_id' => 98748326],
            ['character_id' => (int) $holdingCharacter->character_id, 'corporation_id' => 98760472],
        ]);

    $this->app->instance(EveOnlineProvider::class, $provider);

    $this->artisan('eve:characters:update')->assertSuccessful();

    expect($mainCharacter->user->fresh()->is_member)->toBeTrue()
        ->and($holdingCharacter->user->fresh()->is_member)->toBeFalse();
});
