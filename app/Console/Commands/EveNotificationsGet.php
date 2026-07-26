<?php

namespace App\Console\Commands;

use App\Models\EveNotification;
use App\Services\EveCorporationAccessResolver;
use App\Services\EveOnlineProvider;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

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
                $parsedText = $this->parseNotificationText($notification['text'] ?? '');

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
                        'is_read' => $notification['is_read'] ?? null,
                    ]
                );
            }
        }
        $this->info('Configured corporation notifications have been updated.');

        return $successfulCorporations > 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function parseNotificationText(string $text): array
    {
        try {
            $parsedText = Yaml::parse($text);

            return is_array($parsedText) ? $parsedText : [];
        } catch (ParseException) {
            // Keep collecting the notification if EVE returns malformed YAML.
            preg_match_all('/(\w+): ([\d\.]+)/', $text, $matches, PREG_SET_ORDER);

            return collect($matches)->mapWithKeys(function (array $match): array {
                $value = str_contains($match[2], '.')
                    ? (float) $match[2]
                    : (int) $match[2];

                return [$match[1] => $value];
            })->all();
        }
    }
}
