<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\EveOnlineProvider;

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
        Log::info('Found ' . $characters->count() . ' EVE Characters to verify.');
        $jobs = [];
        foreach ($characters as $character) {
            // Refresh token to keep it active
            if ($character->isTokenExpired()) {
                if (!$provider->refreshToken($character)) {
                    $character->update(['is_valid' => false]);
                    continue;
                }
            }

            // Check scopes stored on the character against expected scopes
            $expectedScopes = explode(' ', config('services.eveonline.scopes'));
            $actualScopes = explode(' ', $character->esi_scopes ?? '');
            $isScopesValid = !array_diff($expectedScopes, $actualScopes);

            $character->update([
                'is_valid' => $isScopesValid,
            ]);
        }

        return Command::SUCCESS;
    }
}
