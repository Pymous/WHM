<?php

use App\Jobs\EveCharactersVerifyJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Update every Token and Scope for all EVE Characters in the database
Schedule::command('eve:characters:verify')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('eve:characters:update')->hourly()->withoutOverlapping();
Schedule::command('eve:corporations:update')->hourly()->withoutOverlapping();
