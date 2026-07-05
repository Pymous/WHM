<?php

namespace App\Console\Commands;

use App\Models\EveNotification;
use App\Services\EveCorporationAccessResolver;
use App\Services\EveOnlineProvider;
use Illuminate\Console\Command;

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
    public function handle(EveOnlineProvider $provider, EveCorporationAccessResolver $access)
    {
        $corporationIds = config('eve.corporations.operational', []);

        if ($corporationIds === []) {
            $this->error('No operational EVE corporations are configured.');

            return Command::FAILURE;
        }

        $this->info('Starting to fetch notifications for configured operational corporations...');
        $successfulCorporations = 0;

        foreach ($corporationIds as $corporationId) {
            $character = $access->notificationReader((int) $corporationId);

            if ($character === null) {
                $this->warn("Corporation {$corporationId}: no authorized notification character is available.");

                continue;
            }

            $notifications = $provider->getNotifications($character);
            if (is_null($notifications)) {
                $this->warn("Corporation {$corporationId}: notifications could not be read using {$character->name}.");

                continue;
            }

            $successfulCorporations++;

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
                        'corporation_id' => $corporationId,
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
        $this->info('Configured corporation notifications have been updated.');

        return $successfulCorporations > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
