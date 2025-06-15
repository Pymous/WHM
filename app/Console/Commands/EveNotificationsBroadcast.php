<?php

namespace App\Console\Commands;

use App\Models\EveCharacter;
use App\Models\EveNotification;
use Illuminate\Console\Command;
use App\Services\EveOnlineProvider;
use Illuminate\Support\Facades\Http;

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
        $this->info('Starting to broadcast notifications...');
        $baseUrl = 'https://discord.com/api/v10';
        $botToken = env('DISCORD_BOT_TOKEN');
        $guildId = env('DISCORD_SERVER_ID');

        // Step 1: Get all channels in the guild
        $channelsUrl = "{$baseUrl}/guilds/{$guildId}/channels";
        $channelsResponse = Http::withHeaders([
            'Authorization' => "Bot {$botToken}",
        ])->get($channelsUrl);

        $channels = collect($channelsResponse->json());

        // Step 2: Find the destination channel
        if (!env('DISCORD_BROADCAST_CHANNEL')) {
            $this->info("Please set the DISCORD_BROADCAST_CHANNEL environment variable to the name of the channel you want to broadcast to.");
            return Command::FAILURE;
        }
        $testChannel = $channels->firstWhere('name', env('DISCORD_BROADCAST_CHANNEL'));

        if (!$testChannel) {
            $this->info("No #" . env('DISCORD_BROADCAST_CHANNEL') . " channel found in the Discord server.");
            return Command::FAILURE;
        }

        $channelId = $testChannel['id'];

        // Step 3: Fetch all notifications of type 'StructureFuelAlert' from the database
        $filter = [
            'TowerAlertMsg',
            'StructureUnderAttack',
            'StructureFuelAlert',
        ];
        $notifications = EveNotification::where(['sender_type' => 'corporation', 'is_broadcasted' => false])->whereIn('type', $filter)->get();
        foreach ($notifications as $notification) {
            // Step 4: Send a message to the channel
            $messageUrl = "{$baseUrl}/channels/{$channelId}/messages";

            $message = $notification->getDiscordBroadcast();
            if (!$message) {
                continue; // Skip if no message is returned
            }

            $messageResponse = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'Content-Type' => 'application/json',
            ])->post($messageUrl, $message);

            if ($messageResponse->successful()) {
                $notification->is_broadcasted = true;
                $notification->save();
            }
        }
        $this->info('Broadcasting completed!');
        $this->info('All notifications have been processed and broadcasted successfully.');
        return Command::SUCCESS;
    }
}
