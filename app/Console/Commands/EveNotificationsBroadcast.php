<?php

namespace App\Console\Commands;

use App\Models\EveNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EveNotificationsBroadcast extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eve:notifications:broadcast';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Broadcast all EVE Notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $corporationIds = config('eve.corporations.operational', []);

        if ($corporationIds === []) {
            $this->error('No operational EVE corporations are configured.');

            return Command::FAILURE;
        }

        $this->info('Starting to broadcast notifications...');
        $baseUrl = 'https://discord.com/api/v10';
        $botToken = config('services.discord.bot_token');
        $guildId = config('services.discord.server_id');
        $channelName = config('services.discord.broadcast_channel');

        if (! $botToken || ! $guildId || ! $channelName) {
            Log::error('EveNotificationsBroadcast: Discord configuration is incomplete.');
            $this->error('Please configure the Discord bot token, server ID, and broadcast channel.');

            return Command::FAILURE;
        }

        // Step 1: Get all channels in the guild
        $channelsUrl = "{$baseUrl}/guilds/{$guildId}/channels";
        $channelsResponse = Http::withHeaders([
            'Authorization' => "Bot {$botToken}",
        ])->get($channelsUrl);

        if (! $channelsResponse->successful()) {
            Log::error('EveNotificationsBroadcast: Discord API error fetching channels', [
                'status' => $channelsResponse->status(),
                'body' => $channelsResponse->body(),
            ]);
            $this->error("Discord API error fetching channels: HTTP {$channelsResponse->status()}.");

            return Command::FAILURE;
        }

        $channels = collect($channelsResponse->json());

        // Step 2: Find the destination channel
        $testChannel = $channels->firstWhere('name', $channelName);

        if (! $testChannel) {
            $this->error("No #{$channelName} channel found in the Discord server.");

            return Command::FAILURE;
        }

        $channelId = $testChannel['id'];

        // Step 3: Fetch all notifications of type 'StructureFuelAlert' from the database
        $filter = [
            'TowerAlertMsg',
            'StructureUnderAttack',
            'StructureFuelAlert',
            'StructureLostShields',
            'StructureNoReagentsAlert',
            'StructureLowReagentsAlert',
            'CharLeftCorpMsg',
            'CharAppAcceptMsg',
            'CorpAppNewMsg',
        ];
        $notifications = EveNotification::query()
            ->with('corporation')
            ->whereIn('sender_type', ['corporation', 'character'])
            ->where('is_broadcasted', false)
            ->where('timestamp', '>=', now()->subHours(24))
            ->whereIn('corporation_id', $corporationIds)
            ->whereIn('type', $filter)
            ->get();
        $failedMessages = 0;

        foreach ($notifications as $notification) {
            // Step 4: Send a message to the channel
            $messageUrl = "{$baseUrl}/channels/{$channelId}/messages";

            $message = $notification->getDiscordBroadcast();
            if (! $message) {
                continue; // Skip if no message is returned
            }

            $corporationLabel = $notification->corporation
                ? "{$notification->corporation->name} [{$notification->corporation->ticker}]"
                : "Corporation #{$notification->corporation_id}";
            $message['embeds'][0]['author'] = ['name' => $corporationLabel];

            $messageResponse = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'Content-Type' => 'application/json',
            ])->post($messageUrl, $message);

            if ($messageResponse->successful()) {
                $notification->is_broadcasted = true;
                $notification->save();
            } else {
                $failedMessages++;
                Log::error('EveNotificationsBroadcast: Discord API error posting notification', [
                    'notification_id' => $notification->notification_id,
                    'status' => $messageResponse->status(),
                    'body' => $messageResponse->body(),
                ]);
            }
        }

        if ($failedMessages > 0) {
            $this->error("{$failedMessages} notification(s) could not be posted to Discord.");

            return Command::FAILURE;
        }

        $this->info('Broadcasting completed!');
        $this->info('All notifications have been processed and broadcasted successfully.');

        return Command::SUCCESS;
    }
}
