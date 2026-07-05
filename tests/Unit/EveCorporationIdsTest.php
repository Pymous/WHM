<?php

use App\Support\EveCorporationIds;

test('corporation IDs are normalized from comma-separated environment values', function () {
    expect(EveCorporationIds::parse(' 98760472,98748326,98760472 '))
        ->toBe([98760472, 98748326])
        ->and(EveCorporationIds::parse('98760472,invalid,-1,0,, 42'))
        ->toBe([98760472, 42])
        ->and(EveCorporationIds::parse(''))
        ->toBe([])
        ->and(EveCorporationIds::holdings('98748326,98760472,98760472', 98748326))
        ->toBe([98760472]);
});
