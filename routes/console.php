<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Update every Token and Scope for all EVE Characters in the database
Schedule::command('eve:characters:verify')->everyFifteenMinutes()->withoutOverlapping()->after(function () {
    Artisan::call('eve:discord:sync-roles');
});
// Update every EVE Characters and EVE Corporations in the database
Schedule::command('eve:characters:update')->hourly()->withoutOverlapping();
// Archive PI tax journal entries before they leave ESI's rolling 30-day wallet history.
Schedule::command('eve:pi-taxes:sync')->hourlyAt(5)->withoutOverlapping();
// Run the eve:discord:structures-summary command every week on friday at 10:00
Schedule::command('eve:discord:structures-summary')->weeklyOn(5, '10:00')->withoutOverlapping();
// Fetch and broadcast notifications for the main and configured holding corporations.
Schedule::command('eve:notifications:get')->everyTwoMinutes()->withoutOverlapping()->after(function () {
    // After fetching notifications, broadcast them to Discord if needed
    Artisan::call('eve:notifications:broadcast');
});
