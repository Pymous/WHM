<?php

namespace App\Console\Commands;

use App\Models\EveCharacter;
use App\Models\EveCorporation;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use App\Services\EveOnlineProvider;

class EveCharactersUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eve:characters:update {name? : The name of a specific character to update}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check every characters currently registered and update their information';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $characterName = $this->argument('name');

        if ($characterName) {
            // Search for a specific character by name
            $characters = EveCharacter::where('name', 'like', "%{$characterName}%")->get();

            if ($characters->isEmpty()) {
                $this->error("No character found with name containing: {$characterName}");
                return Command::FAILURE;
            }

            $this->info("Found " . $characters->count() . " character(s) matching: {$characterName}");
        } else {
            // Get all characters as before
            $characters = EveCharacter::all();

            if ($characters->isEmpty()) {
                $this->info('No EVE Characters found.');
                return Command::SUCCESS;
            }

            $this->info('Found ' . $characters->count() . ' EVE Characters.');
        }

        if ($characters->isEmpty()) {
            $this->info('No EVE Characters found.');
            return Command::SUCCESS;
        }

        $provider = app(EveOnlineProvider::class);
        foreach ($characters as $character) {
            $this->info('Updating character: ' . $character->name);
            try {
                $data = $provider->getCharacterData($character->character_id);
                if ($data) {
                    $this->info('Character updated successfully: ' . $character->name);
                } else {
                    $this->warn('No data found for character: ' . $character->name);
                }

                // Check if the $character esi_expires_at is set and if it is expired
                if ($character->esi_expires_at && $character->esi_expires_at->isPast()) {
                    $this->warn('Character token expired: ' . $character->name . '. Attempting to refresh token...');
                    // Refresh it by using the $provider refreshToken()
                    $provider->refreshToken($character);
                }
            } catch (\Exception $e) {
                $this->error('Error updating character: ' . $character->name . '. Error: ' . $e->getMessage());
            }
        }


        $this->info('Characters update process completed successfully.');
        return Command::SUCCESS;
    }
}
