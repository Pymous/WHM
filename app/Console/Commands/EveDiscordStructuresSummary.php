<?php

namespace App\Console\Commands;

use Illuminate\Support\Carbon;
use App\Models\EveNotification;
use Illuminate\Console\Command;
use App\Services\EveOnlineProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Container\Attributes\Log;

class EveDiscordStructuresSummary extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eve:discord:structures-summary';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('EveDiscordStructuresSummary command started.');

        $provider = app(EveOnlineProvider::class);
        $structures = $provider->getMainStructures();
        /* $station :
        "corporation_id" => 98748326
    "fuel_expires" => "2025-08-10T04:00:00Z"
    "name" => "J121847 - QA Center"
    "profile_id" => 202127
    "reinforce_hour" => 21
    "services" => array:3 [
      0 => array:2 [
        "name" => "Biochemical Reactions"
        "state" => "online"
      ]
      1 => array:2 [
        "name" => "Hybrid Reactions"
        "state" => "online"
      ]
      2 => array:2 [
        "name" => "Reprocessing"
        "state" => "online"
      ]
    ]
    "state" => "shield_vulnerable"
    "structure_id" => 1047256580797
    "system_id" => 31001703
    "type_id" => 35835
    */


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

        $messageUrl = "{$baseUrl}/channels/{$channelId}/messages";


        $structuresStrings = [];

        if ($structures) {
            foreach ($structures as $structure) {
                $timestamp = Carbon::parse($structure['fuel_expires'])->timestamp;
                $structuresStrings[$timestamp] = "**{$structure['name']}** - <t:" . Carbon::parse($structure['fuel_expires'])->timestamp . ':F>';
            }
            ksort($structuresStrings);

            $message = [
                'embeds' => [
                    [
                        'fields' => [
                            [
                                'name' => 'Structures Summary',
                                'value' => implode("\n", $structuresStrings),
                                'inline' => false,
                            ],
                        ],
                        'color' => 3447003,
                    ],
                ],
            ];
        } else {
            $message = [
                'content' => "@here",
                'embeds' => [
                    [
                        'title' => 'No Structures Found',
                        'description' => 'There are no structures to report at this time, maybe check CEO authentification.',
                        'color' => 15158332,
                    ],
                ],
            ];
        }

        $messageResponse = Http::withHeaders([
            'Authorization' => "Bot {$botToken}",
            'Content-Type' => 'application/json',
        ])->post($messageUrl, $message);



        return Command::SUCCESS;
    }
}
