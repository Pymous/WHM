<?php

use App\Models\EveCharacter;
use App\Models\User;
use App\Services\EveOnlineProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function makeEveProvider(array $responses, array &$history = []): EveOnlineProvider
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $provider = new EveOnlineProvider;
    $client = new Client(['handler' => $stack]);
    $property = new ReflectionProperty($provider, 'client');
    $property->setValue($provider, $client);

    return $provider;
}

function makeEveCharacter(array $attributes = []): EveCharacter
{
    $user = User::create(['name' => fake()->unique()->name()]);

    return EveCharacter::create(array_merge([
        'user_id' => $user->id,
        'character_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
        'name' => fake()->name(),
        'is_valid' => true,
        'has_required_scopes' => true,
        'esi_access_token' => 'old-access-token',
        'esi_refresh_token' => 'refresh-token',
        'esi_expires_at' => now()->subMinute(),
        'esi_scopes' => 'scope.one scope.two',
    ], $attributes));
}

test('an expired access token is refreshed before an authenticated request', function () {
    $history = [];
    $provider = makeEveProvider([
        new Response(200, [], json_encode([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 1200,
        ])),
        new Response(200, [], json_encode([['notification_id' => 123]])),
    ], $history);
    $character = makeEveCharacter();

    $notifications = $provider->getNotifications($character);

    expect($notifications)->toBe([['notification_id' => 123]])
        ->and($character->fresh()->is_valid)->toBeTrue()
        ->and($character->fresh()->esi_access_token)->toBe('new-access-token')
        ->and($history)->toHaveCount(2)
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer new-access-token');
});

test('a 401 refreshes the token and retries the request once', function () {
    $history = [];
    $provider = makeEveProvider([
        new Response(401, [], json_encode(['error' => 'token is expired'])),
        new Response(200, [], json_encode([
            'access_token' => 'retried-access-token',
            'refresh_token' => 'retried-refresh-token',
            'expires_in' => 1200,
        ])),
        new Response(200, [], json_encode([['notification_id' => 456]])),
    ], $history);
    $character = makeEveCharacter(['esi_expires_at' => now()->addMinutes(10)]);

    $notifications = $provider->getNotifications($character);

    expect($notifications)->toBe([['notification_id' => 456]])
        ->and($character->fresh()->is_valid)->toBeTrue()
        ->and($history)->toHaveCount(3)
        ->and($history[2]['request']->getHeaderLine('Authorization'))->toBe('Bearer retried-access-token');
});

test('a transient refresh failure does not invalidate the character', function () {
    $history = [];
    $provider = makeEveProvider([
        new Response(503, [], json_encode(['error' => 'temporarily unavailable'])),
    ], $history);
    $character = makeEveCharacter();

    expect($provider->getNotifications($character))->toBeNull()
        ->and($character->fresh()->is_valid)->toBeTrue();
});

test('a 401 followed by a transient refresh failure preserves validity', function () {
    $history = [];
    $provider = makeEveProvider([
        new Response(401, [], json_encode(['error' => 'token is expired'])),
        new Response(503, [], json_encode(['error' => 'temporarily unavailable'])),
    ], $history);
    $character = makeEveCharacter(['esi_expires_at' => now()->addMinutes(10)]);

    expect($provider->getNotifications($character))->toBeNull()
        ->and($character->fresh()->is_valid)->toBeTrue()
        ->and($history)->toHaveCount(2);
});

test('a confirmed invalid refresh grant invalidates the character', function () {
    $history = [];
    $provider = makeEveProvider([
        new Response(400, [], json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'Invalid refresh token',
        ])),
    ], $history);
    $character = makeEveCharacter();

    expect($provider->getNotifications($character))->toBeNull()
        ->and($character->fresh()->is_valid)->toBeFalse();
});

test('the verifier preserves validity during a transient refresh failure', function () {
    $history = [];
    $provider = makeEveProvider([
        new Response(503, [], json_encode(['error' => 'temporarily unavailable'])),
    ], $history);
    $this->app->instance(EveOnlineProvider::class, $provider);
    $character = makeEveCharacter();

    $this->artisan('eve:characters:verify')->assertSuccessful();

    expect($character->fresh()->is_valid)->toBeTrue();
});

test('missing scopes are tracked separately from token validity', function () {
    config(['services.eveonline.scopes' => 'scope.one scope.two']);
    $character = makeEveCharacter([
        'esi_expires_at' => now()->addMinutes(10),
        'esi_scopes' => 'scope.one',
    ]);

    $this->artisan('eve:characters:verify')->assertSuccessful();

    expect($character->fresh()->is_valid)->toBeTrue()
        ->and($character->fresh()->has_required_scopes)->toBeFalse();
});
