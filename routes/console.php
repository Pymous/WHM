<?php

use App\Jobs\EveCharactersVerifyJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Update every Token and Scope for all EVE Characters in the database
Schedule::command('eve:characters:verify')->everyFifteenMinutes()->withoutOverlapping()->after(function () {
    Artisan::call('eve:discord:sync-roles');
});
// Update every EVE Characters and EVE Corporations in the database
Schedule::command('eve:characters:update')->hourly()->withoutOverlapping();
// Run the eve:discord:structures-summary command every day at 12pm
Schedule::command('eve:discord:structures-summary')->dailyAt('12:00')->withoutOverlapping();
// Get all EVE Notifications from all the available directors from the main corporation
Schedule::command('eve:notifications:get')->everyTwoMinutes()->withoutOverlapping()->after(function () {
    // After fetching notifications, broadcast them to Discord if needed
    Artisan::call('eve:notifications:broadcast');
});
