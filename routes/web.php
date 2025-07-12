<?php

use App\Models\User;
use Inertia\Inertia;
use App\Models\EveCharacter;
use App\Services\EveOnlineProvider;
use App\Http\Middleware\UserIsAdmin;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EsiController;
use App\Http\Controllers\EveController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\DiscordController;
use App\Models\EveUniverse;

Route::get('/', function () {
    return Inertia::render('Landing');
})->name('landing');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth'])->name('dashboard');


// Discord Authentication Routes
Route::get('/discord/auth/login', [DiscordController::class, 'authLogin'])->name('discord.auth.login');
Route::get('/discord/auth/callback', [DiscordController::class, 'authCallback'])->name('discord.auth.callback');

// ESI Authentication Routes
Route::get('/esi/auth/login', [EsiController::class, 'authLogin'])->name('esi.auth.login');
Route::get('/esi/auth/logout', [EsiController::class, 'authLogout'])->name('esi.auth.logout');
Route::get('/esi/auth/callback', [EsiController::class, 'authCallback'])->name('esi.auth.callback');
Route::get('/esi/corp', [EsiController::class, 'corp'])->name('esi.corp');

// EVE Routes
Route::group(['prefix' => 'eve', 'middleware' => ['auth']], function () {
    Route::post('/characters/{character}/make-primary', [EveController::class, 'makePrimary'])->name('eve.characters.make-primary');
    Route::post('/characters/{character}/remove', [EveController::class, 'remove'])->name('eve.characters.remove');
});

// Admin Routes
Route::group(['prefix' => 'admin', 'middleware' => [UserIsAdmin::class]], function () {
    Route::get('/users', [AdminController::class, 'usersIndex'])->name('admin.users');
    Route::get('/users/discord/sync', [AdminController::class, 'usersForceDiscordSync'])->name('admin.users.force-discord-sync');
    Route::get('/fittings', [AdminController::class, 'fittingsIndex'])->name('admin.fittings');
    Route::post('/fittings', [AdminController::class, 'fittingsConvertSkillPlan'])->name('admin.fittings.convert');
    Route::post('/fittings/check/{eveCharacter}', [AdminController::class, 'fittingsCheck'])->name('admin.fittings.check');
});

Route::get('/test', function () {
    $skills = "Spaceship Command 1
Gallente Frigate 1
Gallente Frigate 2
Gallente Frigate 3
Gallente Destroyer 1
Gallente Destroyer 2
Gallente Destroyer 3
Spaceship Command 2
Gallente Cruiser 1
Gallente Cruiser 2
Gallente Cruiser 3
Spaceship Command 3
Gallente Battlecruiser 1
Gallente Battlecruiser 2
Gallente Battlecruiser 3
Spaceship Command 4
Gallente Battleship 1
Amarr Frigate 1
Amarr Frigate 2
Amarr Frigate 3
Amarr Destroyer 1
Amarr Destroyer 2
Amarr Destroyer 3
Amarr Cruiser 1
Amarr Cruiser 2
Amarr Cruiser 3
Amarr Battlecruiser 1
Amarr Battlecruiser 2
Amarr Battlecruiser 3
Amarr Battleship 1
Mechanics 1
Hull Upgrades 1
Hull Upgrades 2
Hull Upgrades 3
Hull Upgrades 4
Science 1
Power Grid Management 1
Power Grid Management 2
Energy Grid Upgrades 1
Energy Grid Upgrades 2
Hull Upgrades 5
Drones 1
Drones 2
Drones 3
Drones 4
Drones 5
Drone Interfacing 1
Drone Interfacing 2
Drone Interfacing 3
Drone Interfacing 4
Drone Sharpshooting 1
Drone Sharpshooting 2
Drone Sharpshooting 3
Drone Sharpshooting 4
Sentry Drone Interfacing 1
Science 2
Science 3
Cybernetics 1
Cybernetics 2
Heavy Drone Operation 1
Navigation 1
Afterburner 1
Afterburner 2
Afterburner 3
Navigation 2
Navigation 3
High Speed Maneuvering 1
Medium Drone Operation 1
Cybernetics 3
CPU Management 1
Electronic Warfare 1
Electronic Warfare 2
Electronic Warfare 3
Electronic Warfare 4
Advanced Drone Avionics 1
CPU Management 2
Long Range Targeting 1
Long Range Targeting 2
Long Range Targeting 3
Long Range Targeting 4
Mechanics 2
Mechanics 3
Repair Systems 1
Repair Systems 2
Remote Armor Repair Systems 1
Remote Armor Repair Systems 2
Remote Armor Repair Systems 3
Remote Armor Repair Systems 4
Cybernetics 4
Advanced Drone Avionics 2
Advanced Drone Avionics 3
Capacitor Systems Operation 1
Capacitor Systems Operation 2
Capacitor Systems Operation 3
Capacitor Systems Operation 4";

    $eveProvider = app(EveOnlineProvider::class);

    $skills = explode("\n", $skills);
    // Loop a first time on each $skills, and only keep the highest level for each skill
    $skills = array_reduce($skills, function ($carry, $skill) {
        $skill = trim($skill);
        if (empty($skill)) {
            return $carry;
        }
        $parts = explode(' ', $skill);
        $name = implode(' ', array_slice($parts, 0, -1));
        $level = (int) end($parts);
        if (!isset($carry[$name]) || $carry[$name] < $level) {
            $carry[$name] = $level;
        }
        return $carry;
    }, []);

    // Loop over each skill, and get the skill name in $name and the level in $level
    $skillsPlan = [];
    foreach ($skills as $name => $level) {
        // Look for each EveUniverse model that has the name we're looking for, knowing that you need to check EveUniverse->content['nape'] (it's a JSON array)
        $eveSkill = EveUniverse::whereJsonContains('content->name', $name)
            ->first();

        $skillsPlan[$eveSkill->item_id] = [
            'name' => $eveSkill->content['name'],
            'level' => $level,
            'trained' => false,
        ];
    }

    // return $skillsPlan;


    $character = EveCharacter::find(19);
    $characterSkills = $eveProvider->getSkills($character);

    foreach ($characterSkills['skills'] as $skill) {
        if (isset($skillsPlan[$skill['skill_id']])) {
            if ($skill['trained_skill_level'] >= $skillsPlan[$skill['skill_id']]['level']) {
                $skillsPlan[$skill['skill_id']]['trained'] = true;
            }
        }
    }

    dd($skillsPlan);
});

require __DIR__ . '/settings.php';
