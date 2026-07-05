<?php

use App\Models\EveCharacter;
use App\Models\User;
use App\Services\EveOnlineProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

test('corporation ESI methods use the requested corporation and actor token', function () {
    $history = [];
    $mock = new MockHandler([
        new Response(200, [], json_encode([['structure_id' => 1001]])),
        new Response(200, [], json_encode([['item_id' => 1002]])),
        new Response(200, [], json_encode([['starbase_id' => 1003]])),
        new Response(200, [], json_encode(['fuels' => []])),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $provider = new EveOnlineProvider;
    $clientProperty = new ReflectionProperty($provider, 'client');
    $clientProperty->setValue($provider, new Client(['handler' => $stack]));

    $user = User::create(['name' => 'Holding Corp Operator']);
    $actor = EveCharacter::create([
        'user_id' => $user->id,
        'character_id' => '2119263895',
        'name' => 'Holding Director',
        'corporation_id' => 98760472,
        'is_valid' => true,
        'has_required_scopes' => true,
        'esi_access_token' => 'holding-access-token',
        'esi_refresh_token' => 'holding-refresh-token',
        'esi_expires_at' => now()->addHour(),
    ]);

    expect($provider->getCorporationStructures(98760472, $actor))->toHaveCount(1)
        ->and($provider->getCorporationAssets(98760472, $actor))->toHaveCount(1)
        ->and($provider->getCorporationStarbases(98760472, $actor))->toHaveCount(1)
        ->and($provider->getCorporationStarbaseDetail(98760472, $actor, 1003, 30000142))
        ->toBe(['fuels' => []]);

    expect($history)->toHaveCount(4);

    foreach ($history as $request) {
        expect($request['request']->getUri()->getPath())->toContain('/corporations/98760472/')
            ->and($request['request']->getHeaderLine('Authorization'))->toBe('Bearer holding-access-token');
    }
});
