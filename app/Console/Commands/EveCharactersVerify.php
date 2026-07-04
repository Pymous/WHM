<?php

namespace App\Console\Commands;

use App\Services\EveOnlineProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EveCharactersVerify extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eve:characters:verify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify all the EVE Characters currently in the database to update tokens and scopes';

    /**
     * Execute the console command.
     */
    public function handle(EveOnlineProvider $provider)
    {
        Log::info('Starting verification of EVE Characters...');
        $characters = \App\Models\EveCharacter::all();
        if ($characters->isEmpty()) {
            Log::info('No EVE Characters found in the database.');

            return Command::SUCCESS;
        }
        Log::info('Found '.$characters->count().' EVE Characters to verify.');
        foreach ($characters as $character) {
            // Refresh expired tokens and retry characters invalidated by the old
            // access-token 401 handling. Only refreshToken() may invalidate a
            // character, and only for a permanent OAuth rejection.
            if (! $character->is_valid || $character->isTokenExpired()) {
                if (! $provider->refreshToken($character)) {
                    continue;
                }
            }

            $character->update([
                'has_required_scopes' => $provider->hasRequiredScopes($character->esi_scopes),
            ]);
        }

        return Command::SUCCESS;
    }
}
