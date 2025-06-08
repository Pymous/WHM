<?php

use App\Jobs\EveCharactersVerifyJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Update every Token and Scope for all EVE Characters in the database
Schedule::command('eve:characters:verify')->everyFifteenMinutes()->withoutOverlapping();
// TODO : Update corps info, what would be a good trigger ? Once every 30 minutes and on a new login ?