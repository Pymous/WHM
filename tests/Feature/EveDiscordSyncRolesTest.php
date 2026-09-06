<?php

use App\Models\EveCharacter;
use App\Models\User;
use App\Services\EveCorporationAccessResolver;
use App\Services\EveOnlineProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('Discord role sync uses configured credentials and a scoped corporation actor', function () {
    $corporationId = 98748326;
    config([
        'eve.corporations.main' => $corporationId,
        'services.discord.bot_token' => 'cached-bot-token',
        'services.discord.server_id' => 'guild-id',
    ]);

    $user = User::create([
        'name' => 'Director User',
        'discord_id' => 'discord-user-id',
    ]);
    $actor = EveCharacter::create([
        'user_id' => $user->id,
        'character_id' => '274477726',
        'name' => 'Director Character',
        'corporation_id' => $corporationId,
        'is_primary' => true,
        'is_valid' => true,
        'esi_access_token' => 'access-token',
        'esi_refresh_token' => 'refresh-token',
        'esi_expires_at' => now()->addHour(),
    ]);

    $requiredScopes = [
        'esi-characters.read_corporation_roles.v1',
        'esi-corporations.read_corporation_membership.v1',
        'esi-corporations.read_titles.v1',
    ];
    $access = Mockery::mock(EveCorporationAccessResolver::class);
    $access->shouldReceive('director')
        ->once()
        ->with($corporationId, $requiredScopes)
        ->andReturn($actor);

    $provider = Mockery::mock(EveOnlineProvider::class);
    $provider->shouldReceive('setCaller')->once()->with($actor);
    $provider->shouldReceive('getCorporationTitles')->once()->with($corporationId)->andReturn([
        ['title_id' => 1, 'name' => 'Officer'],
    ]);
    $provider->shouldReceive('getCorporationDirectors')->once()->with($corporationId)->andReturn([
        (int) $actor->character_id,
    ]);
    $provider->shouldReceive('getCorporationMembersTitles')->once()->with($corporationId)->andReturn([[
        'character_id' => (int) $actor->character_id,
        'titles' => [1],
    ]]);

    $this->app->instance(EveCorporationAccessResolver::class, $access);
    $this->app->instance(EveOnlineProvider::class, $provider);

    Http::fake(function (Request $request) {
        if ($request->method() === 'PATCH') {
            return Http::response([], 204);
        }

        if (str_ends_with($request->url(), '/members?limit=1000')) {
            return Http::response([[
                'user' => ['id' => 'discord-user-id'],
                'roles' => ['other-role'],
            ]]);
        }

        if (str_ends_with($request->url(), '/roles')) {
            return Http::response([
                ['id' => 'member-role', 'name' => 'Member'],
                ['id' => 'director-role', 'name' => 'Director'],
                ['id' => 'officer-role', 'name' => 'Officer'],
                ['id' => 'other-role', 'name' => 'Unmanaged'],
            ]);
        }

        return Http::response([], 404);
    });

    $this->artisan('eve:discord:sync-roles')->assertSuccessful();

    expect($user->fresh()->is_member)->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'PATCH') {
            return false;
        }

        return $request->hasHeader('Authorization', 'Bot cached-bot-token')
            && data_get($request->data(), 'nick') === '[FO2RE] Director Character'
            && collect(data_get($request->data(), 'roles'))->sort()->values()->all() === [
                'director-role',
                'member-role',
                'officer-role',
                'other-role',
            ];
    });
});

test('Discord role sync fails cleanly when Discord rejects the configured token', function () {
    config([
        'eve.corporations.main' => 98748326,
        'services.discord.bot_token' => 'rejected-token',
        'services.discord.server_id' => 'guild-id',
    ]);

    Http::fake([
        '*' => Http::response(['message' => '401: Unauthorized', 'code' => 0], 401),
    ]);

    $this->artisan('eve:discord:sync-roles')
        ->expectsOutput('Discord API error fetching members: HTTP 401.')
        ->assertFailed();
});
