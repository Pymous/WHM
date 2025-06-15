<?php

namespace App\Console\Commands;

use App\Models\EveCharacter;
use App\Models\EveNotification;
use Illuminate\Console\Command;
use App\Services\EveOnlineProvider;

class EveNotificationsGet extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eve:notifications:get';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get all EVE Notifications from all the available characters in the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to fetch notifications for all characters in the database...');
        $provider = app(EveOnlineProvider::class);
        $characters = EveCharacter::all();
        foreach ($characters as $character) {
            $notifications = $provider->getNotifications($character);
            if (is_null($notifications)) {
                continue;
            }
            foreach ($notifications as $notification) {
                $text = $notification['text'] ?? '';
                $parsedText = [];

                preg_match_all('/(\w+): ([\d\.]+)/', $text, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $key = $match[1];
                    $value = $match[2];

                    // Convert numeric values to appropriate types
                    if (is_numeric($value)) {
                        if (strpos($value, '.') !== false) {
                            $parsedText[$key] = (float) $value;
                        } else {
                            $parsedText[$key] = (int) $value;
                        }
                    } else {
                        $parsedText[$key] = $value;
                    }
                }


                EveNotification::updateOrCreate(
                    ['notification_id' => $notification['notification_id']],
                    [
                        'character_id' => $character->character_id,
                        'type' => $notification['type'],
                        'sender_id' => $notification['sender_id'],
                        'sender_type' => $notification['sender_type'],
                        'timestamp' => $notification['timestamp'],
                        'text' => $parsedText,
                        'is_read' => @$notification['is_read'],
                    ]
                );
            }
        }
        $this->info('All notifications have been updated or created successfully.');
        return Command::SUCCESS;
    }
}
