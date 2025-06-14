<?php

namespace App\Console\Commands;

use App\Models\EveCharacter;
use Illuminate\Console\Command;
use App\Models\EveCorporation;
use App\Services\EveOnlineProvider;

class EveCorporationsUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eve:corporations:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check every characters currently registered and update their corporation information';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $characters = \App\Models\EveCharacter::whereNotNull('corporation_id')->get();
        if ($characters->isEmpty()) {
            $this->info('No EVE Characters found with a corporation ID.');
            return Command::SUCCESS;
        }
        $this->info('Found ' . $characters->count() . ' EVE Characters with a corporation ID.');

        // $corporations is a collection of unique corporations from the $characters collection
        $corporations = $characters->map(function ($character) {
            return $character->corporation_id;
        })->unique('corporation_id');

        if ($corporations->isEmpty()) {
            $this->info('No unique EVE Corporations found to update.');
            return Command::SUCCESS;
        }

        $this->info('Found ' . $corporations->count() . ' EVE Corporations to update.');
        foreach ($corporations as $corporation) {
            // Get EveOnlineProvider instance
            $provider = app(EveOnlineProvider::class);
            $data = $provider->getCorporationData($corporation);
            if ($data) {
                // Update or create based on corporation_id
                EveCorporation::updateOrCreate(
                    ['corporation_id' => $corporation],
                    [
                        'name' => $data['name'],
                        'ticker' => $data['ticker'],
                        'description' => $data['description'] ?? null,
                        'url' => $data['url'] ?? null,
                        'ceo_id' => $data['ceo_id'] ?? null,
                        'creator_id' => $data['creator_id'] ?? null,
                        'date_founded' => $data['date_founded'] ?? null,
                        'home_station_id' => $data['home_station_id'] ?? null,
                        'member_count' => $data['member_count'] ?? 0,
                        'tax_rate' => $data['tax_rate'] ?? 0.0,
                        'war_eligible' => $data['war_eligible'] ?? true,
                    ]
                );
            }
        }
        $this->info('Corporations update process completed successfully.');
        return Command::SUCCESS;
    }
}
