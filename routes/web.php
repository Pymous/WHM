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

require __DIR__ . '/settings.php';
