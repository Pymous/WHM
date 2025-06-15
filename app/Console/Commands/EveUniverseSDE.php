<?php

namespace App\Console\Commands;

use App\Models\EveUniverse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

class EveUniverseSDE extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eve:universe:sde';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate YAML files from the SDE to the EVE Universe table';

    /**
     * List of files to process. If empty, all files will be processed.
     *
     * @var array
     */
    protected $whitelist = [
        // Add filenames to whitelist, for example:
        'fsd/types.yaml',
        'bsd/invNames.yaml',
        // 'typeMaterials.yaml'
    ];

    /**
     * Batch size for database operations
     * 
     * @var int
     */
    protected $batchSize = 1000;

    /**
     * Fields that should be filtered to keep only English language
     * 
     * @var array
     */
    protected $languageFields = ['name', 'description'];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Remove the memory limit to allow processing large files
        ini_set('memory_limit', '-1');
        // Set the maximum execution time to unlimited
        set_time_limit(0);

        $directory = storage_path('sde');

        // Check if directory exists
        if (!File::isDirectory($directory)) {
            $this->error("Directory $directory does not exist!");
            return 1;
        }

        // Build list of files to process based on whitelist and subfolders
        $yamlFiles = [];
        foreach ($this->whitelist as $relativePath) {
            $fullPath = $directory . DIRECTORY_SEPARATOR . $relativePath;
            if (File::exists($fullPath)) {
                $yamlFiles[] = new \SplFileInfo($fullPath);
            }
        }

        $totalFiles = count($yamlFiles);

        if ($totalFiles === 0) {
            $this->warn("No YAML files found to process. Check your whitelist configuration.");
            return 1;
        }

        $this->info("Found $totalFiles YAML files to process.");

        foreach ($yamlFiles as $file) {
            $filename = $file->getFilename();
            $type = pathinfo($filename, PATHINFO_FILENAME);

            $this->info("Processing $filename...");
            $startTime = microtime(true);

            try {
                $this->processYamlFile($file, $type);

                $endTime = microtime(true);
                $processingTime = round($endTime - $startTime, 2);
                $this->info("Completed processing $filename in $processingTime seconds");
            } catch (\Exception $e) {
                $this->error("Error processing file $filename: " . $e->getMessage());
            }
        }

        $this->info("Migration completed!");

        return Command::SUCCESS;
    }

    /**
     * Process a YAML file with optimized approach
     */
    protected function processYamlFile($file, $type)
    {
        $content = File::get($file->getPathname());
        $data = Yaml::parse($content);
        unset($content); // Free memory

        if (!is_array($data)) {
            $this->warn("No data found in file: " . $file->getFilename());
            return;
        }

        $this->info("Found " . count($data) . " items to process");
        $bar = $this->output->createProgressBar(count($data));
        $bar->start();

        $batch = [];
        $count = 0;
        $totalItems = 0;

        DB::beginTransaction();

        try {
            foreach ($data as $id => $itemContent) {
                // Filter language fields to keep only English data
                $itemContent = $this->filterLanguageFields($itemContent);

                if (@$itemContent['itemID']) {
                    $id = $itemContent['itemID'];
                }

                $batch[] = [
                    'item_id' => $id,
                    'type' => $type,
                    'content' => json_encode($itemContent),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $count++;
                $totalItems++;

                // Process in batches to save memory and improve performance
                if ($count >= $this->batchSize) {
                    $this->insertBatch($batch);
                    $batch = [];
                    $count = 0;
                }

                $bar->advance();
            }

            // Insert any remaining items
            if (count($batch) > 0) {
                $this->insertBatch($batch);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Imported $totalItems items from " . $file->getFilename());
    }

    /**
     * Filter multilanguage fields to keep only English language
     * 
     * @param array $itemContent
     * @return array
     */
    protected function filterLanguageFields($itemContent)
    {
        foreach ($this->languageFields as $field) {
            if (isset($itemContent[$field]) && is_array($itemContent[$field])) {
                if (isset($itemContent[$field]['en'])) {
                    $itemContent[$field] = $itemContent[$field]['en'];
                } else {
                    // If no English, take the first available language
                    $itemContent[$field] = reset($itemContent[$field]);
                }
            }
        }

        return $itemContent;
    }

    /**
     * Insert a batch of records efficiently
     */
    protected function insertBatch($batch)
    {
        EveUniverse::upsert(
            $batch,
            ['item_id', 'type'],
            ['content', 'updated_at']
        );
    }
}
