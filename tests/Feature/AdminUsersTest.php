<?php

use App\Models\EveCharacter;
use App\Models\User;

test('an admin can change another users primary character', function () {
    $admin = User::create(['name' => 'Admin One', 'is_admin' => true]);
    $user = User::create(['name' => 'Member One']);
    $oldPrimary = EveCharacter::create([
        'user_id' => $user->id,
        'character_id' => '1000001',
        'name' => 'Old Primary',
        'is_primary' => true,
    ]);
    $newPrimary = EveCharacter::create([
        'user_id' => $user->id,
        'character_id' => '1000002',
        'name' => 'New Primary',
        'is_primary' => false,
    ]);

    $this->actingAs($admin)
        ->patchJson(route('admin.users.characters.make-primary', [$user, $newPrimary]))
        ->assertSuccessful();

    expect($oldPrimary->fresh()->is_primary)->toBeFalse()
        ->and($newPrimary->fresh()->is_primary)->toBeTrue();
});

test('an admin cannot assign a character belonging to another user', function () {
    $admin = User::create(['name' => 'Admin Two', 'is_admin' => true]);
    $user = User::create(['name' => 'Member Two']);
    $otherUser = User::create(['name' => 'Member Three']);
    $character = EveCharacter::create([
        'user_id' => $otherUser->id,
        'character_id' => '1000003',
        'name' => 'Someone Elses Character',
        'is_primary' => false,
    ]);

    $this->actingAs($admin)
        ->patchJson(route('admin.users.characters.make-primary', [$user, $character]))
        ->assertNotFound();

    expect($character->fresh()->is_primary)->toBeFalse();
});

test('a non-admin cannot change another users primary character', function () {
    $user = User::create(['name' => 'Member Four']);
    $otherUser = User::create(['name' => 'Member Five']);
    $character = EveCharacter::create([
        'user_id' => $otherUser->id,
        'character_id' => '1000004',
        'name' => 'Protected Character',
        'is_primary' => false,
    ]);

    $this->actingAs($user)
        ->patch(route('admin.users.characters.make-primary', [$otherUser, $character]))
        ->assertRedirect(route('dashboard'));

    expect($character->fresh()->is_primary)->toBeFalse();
});
