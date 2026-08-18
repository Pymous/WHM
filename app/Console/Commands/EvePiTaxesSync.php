<?php

namespace App\Console\Commands;

use App\Services\EvePiTaxSyncService;
use Illuminate\Console\Command;
use Throwable;

class EvePiTaxesSync extends Command
{
    protected $signature = 'eve:pi-taxes:sync {corporation_id? : Corporation to synchronize}';

    protected $description = 'Archive corporation PI import and export tax journal entries';

    public function handle(EvePiTaxSyncService $sync): int
    {
        $corporationId = (int) ($this->argument('corporation_id') ?: config('eve.corporations.main'));

        if (! $corporationId) {
            $this->error('No EVE corporation is configured.');

            return self::FAILURE;
        }

        try {
            $result = $sync->sync($corporationId);
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(
            "Archived {$result['stored']} PI tax entries from {$result['pages']} wallet pages using {$result['actor']}.",
        );

        return self::SUCCESS;
    }
}
