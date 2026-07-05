<?php

use App\Models\EveCharacter;
use App\Models\User;
use App\Services\EveCorporationAccessResolver;
use App\Services\EveOnlineProvider;
use Illuminate\Support\Facades\Cache;

function makeCorporationAccessCharacter(int $corporationId, int $characterId, array $attributes = []): EveCharacter
{
    $user = User::create(['name' => "User {$characterId}"]);

    return EveCharacter::create(array_merge([
        'user_id' => $user->id,
        'character_id' => (string) $characterId,
        'name' => "Character {$characterId}",
        'corporation_id' => $corporationId,
        'is_valid' => true,
        'has_required_scopes' => true,
        'esi_access_token' => 'access-token',
        'esi_refresh_token' => 'refresh-token',
        'esi_expires_at' => now()->addHour(),
    ], $attributes));
}

beforeEach(function () {
    Cache::flush();
});

test('the linked CEO is preferred without a corporation role lookup', function () {
    $corporationId = 98760472;
    $ceo = makeCorporationAccessCharacter($corporationId, 2119263895);
    makeCorporationAccessCharacter($corporationId, 2119263896);

    $provider = Mockery::mock(EveOnlineProvider::class);
    $provider->shouldReceive('getCorporationData')
        ->once()
        ->with($corporationId)
        ->andReturn(['ceo_id' => (int) $ceo->character_id]);
    $provider->shouldNotReceive('getCharacterCorporationRoles');

    $resolver = new EveCorporationAccessResolver($provider);

    expect($resolver->director($corporationId)->is($ceo))->toBeTrue();
});

test('a Director is selected when the CEO is not linked', function () {
    $corporationId = 98760473;
    $director = makeCorporationAccessCharacter($corporationId, 2119263900);

    $provider = Mockery::mock(EveOnlineProvider::class);
    $provider->shouldReceive('getCorporationData')
        ->once()
        ->andReturn(['ceo_id' => 2119263999]);
    $provider->shouldReceive('getCharacterCorporationRoles')
        ->once()
        ->with(Mockery::on(fn (EveCharacter $character): bool => $character->is($director)))
        ->andReturn(['roles' => ['Director']]);

    $resolver = new EveCorporationAccessResolver($provider);

    expect($resolver->director($corporationId)->is($director))->toBeTrue();
});

test('a Station Manager provides structures and notifications but not Director access', function () {
    $corporationId = 98760474;
    $stationManager = makeCorporationAccessCharacter($corporationId, 2119263901);

    $provider = Mockery::mock(EveOnlineProvider::class);
    $provider->shouldReceive('getCorporationData')
        ->once()
        ->andReturn(['ceo_id' => 2119263999]);
    $provider->shouldReceive('getCharacterCorporationRoles')
        ->once()
        ->with(Mockery::on(fn (EveCharacter $character): bool => $character->is($stationManager)))
        ->andReturn(['roles' => ['Station_Manager']]);

    $resolver = new EveCorporationAccessResolver($provider);

    expect($resolver->director($corporationId))->toBeNull()
        ->and($resolver->structureManager($corporationId)->is($stationManager))->toBeTrue()
        ->and($resolver->notificationReader($corporationId)->is($stationManager))->toBeTrue();
});

test('invalid or incompletely scoped characters are not access candidates', function () {
    $corporationId = 98760475;
    makeCorporationAccessCharacter($corporationId, 2119263902, ['is_valid' => false]);
    makeCorporationAccessCharacter($corporationId, 2119263903, ['has_required_scopes' => false]);

    $provider = Mockery::mock(EveOnlineProvider::class);
    $provider->shouldReceive('getCorporationData')
        ->once()
        ->andReturn(['ceo_id' => 2119263902]);
    $provider->shouldNotReceive('getCharacterCorporationRoles');

    $resolver = new EveCorporationAccessResolver($provider);

    expect($resolver->director($corporationId))->toBeNull();
});
