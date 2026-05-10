<?php

namespace App\Console\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use App\Services\EveOnlineProvider;
use App\Services\EvePosFuelCalculator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EveDiscordStructuresSummary extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eve:discord:structures-summary {--debug : Dump raw API data to the console instead of posting to Discord}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Post a weekly fuel-expiry summary for all Upwell structures and online POS to Discord';

    /**
     * Execute the console command.
     */
    public function handle(EveOnlineProvider $provider, EvePosFuelCalculator $calculator)
    {
        $this->info('EveDiscordStructuresSummary command started.');

        // ── Discord bootstrap ────────────────────────────────────────────────
        $baseUrl   = 'https://discord.com/api/v10';
        $botToken  = env('DISCORD_BOT_TOKEN');
        $guildId   = env('DISCORD_SERVER_ID');
        $channelName = env('DISCORD_BROADCAST_CHANNEL');

        if (!$channelName) {
            $this->error('Please set the DISCORD_BROADCAST_CHANNEL environment variable.');
            return Command::FAILURE;
        }

        $channelsResponse = Http::withHeaders(['Authorization' => "Bot {$botToken}"])
            ->get("{$baseUrl}/guilds/{$guildId}/channels");

        if (!$channelsResponse->successful()) {
            $this->error("Discord API error fetching channels: HTTP {$channelsResponse->status()} — " . $channelsResponse->body());
            return Command::FAILURE;
        }

        $channels  = collect($channelsResponse->json());
        $channel   = $channels->firstWhere('name', $channelName);

        if (!$channel) {
            $names = $channels->pluck('name')->filter()->join(', ');
            $this->error("No #{$channelName} channel found. Available channels: {$names}");
            return Command::FAILURE;
        }

        $messageUrl = "{$baseUrl}/channels/{$channel['id']}/messages";

        // ── Upwell structures ────────────────────────────────────────────────
        $structures = $provider->getMainStructures() ?? [];

        $upwellLines = [];
        foreach ($structures as $s) {
            if (empty($s['fuel_expires'])) {
                continue;
            }
            $ts = Carbon::parse($s['fuel_expires'])->timestamp;
            $upwellLines[$ts] = "**{$s['name']}** - <t:{$ts}:F> (<t:{$ts}:R>)";
        }
        ksort($upwellLines);

        // ── POS / starbases ──────────────────────────────────────────────────
        $starbases  = $provider->getMainStarbases() ?? [];
        $posLines   = [];
        $offlinePosLines = [];
        $debug = $this->option('debug');

        if ($debug) {
            $this->line('<info>[DEBUG]</info> getMainStarbases() returned ' . count($starbases) . ' entries.');
            if (empty($starbases)) {
                $this->warn('[DEBUG] No starbases returned — check CEO token has esi-corporations.read_starbases.v1 scope.');
            }
        }

        // ── Collect all IDs that need name resolution ────────────────────────
        // Gather type IDs (tower types), system IDs from all starbases,
        // plus fuel type IDs from online POS detail fetches.
        // Moon IDs (celestials, 40000000+) must be resolved separately.
        $idsToResolve = [];
        $moonIds      = [];
        $starbaseDetails = []; // keyed by starbase_id, pre-fetched below

        foreach ($starbases as $pos) {
            $idsToResolve[] = (int) $pos['type_id'];
            $idsToResolve[] = (int) $pos['system_id'];
            if (isset($pos['moon_id'])) {
                $moonIds[] = (int) $pos['moon_id'];
            }

            if (($pos['state'] ?? '') === 'online') {
                $detail = $provider->getMainStarbaseDetail((int) $pos['starbase_id'], (int) $pos['system_id']);
                $starbaseDetails[$pos['starbase_id']] = $detail;
                foreach ($detail['fuels'] ?? [] as $f) {
                    $idsToResolve[] = (int) $f['type_id'];
                }
            }
        }

        $names = $provider->resolveNames($idsToResolve);

        // Resolve moon names via dedicated endpoint (not supported by /universe/names/)
        foreach (array_unique($moonIds) as $moonId) {
            $moonName = $provider->resolveMoonName($moonId);
            if ($moonName !== null) {
                $names[$moonId] = $moonName;
            }
        }

        if ($debug) {
            $this->line('<info>[DEBUG]</info> resolveNames() resolved ' . count($names) . ' IDs.');
        }

        foreach ($starbases as $pos) {
            $state = $pos['state'] ?? 'offline';

            $typeId   = (int) $pos['type_id'];
            $systemId = (int) $pos['system_id'];
            $moonId   = isset($pos['moon_id']) ? (int) $pos['moon_id'] : null;

            $typeName   = $names[$typeId]   ?? "Type #{$typeId}";
            $systemName = $names[$systemId] ?? "System #{$systemId}";
            $moonName   = $moonId ? ($names[$moonId] ?? "Moon #{$moonId}") : null;

            $location = $moonName ?? $systemName;

            if ($debug) {
                $this->line("<info>[DEBUG]</info> POS starbase_id={$pos['starbase_id']} type={$typeName} system={$systemName} moon=" . ($moonName ?? 'n/a') . " state={$state}");
            }

            // Non-online POSes have no fuel bay data. List them separately.
            if ($state !== 'online') {
                $offlinePosLines[] = "**{$typeName}** - {$location} *({$state})*";
                continue;
            }

            $detail  = $starbaseDetails[$pos['starbase_id']] ?? [];
            $fuelBay = $detail['fuels'] ?? [];

            if ($debug) {
                $this->line('<info>[DEBUG]</info>   fuel bay entries: ' . count($fuelBay));
                foreach ($fuelBay as $f) {
                    $fuelName = $names[(int) $f['type_id']] ?? "Type #{$f['type_id']}";
                    $this->line("<info>[DEBUG]</info>     {$fuelName} (type_id={$f['type_id']}) quantity={$f['quantity']}");
                }
            }

            $runway = $calculator->calculateRunway($typeId, $fuelBay);

            if ($debug) {
                $this->line('<info>[DEBUG]</info>   runway: hours_remaining=' . ($runway['hours_remaining'] ?? 'null')
                    . ' limiting_type=' . ($runway['limiting_fuel_type_id'] ?? 'null')
                    . ' qty=' . ($runway['limiting_fuel_quantity'] ?? 'null')
                    . ' rate=' . ($runway['limiting_fuel_consumption_per_hour'] ?? 'null'));
            }

            if ($runway['fuel_expires'] === null) {
                $posLines[PHP_INT_MAX] = "**{$typeName}** - {$location} - *fuel unknown*";
                continue;
            }

            $ts = $runway['fuel_expires']->timestamp;

            $limitingName = $runway['limiting_fuel_type_id']
                ? ($names[$runway['limiting_fuel_type_id']] ?? "Type #{$runway['limiting_fuel_type_id']}")
                : null;

            $detail_str = $limitingName
                ? " - {$runway['limiting_fuel_quantity']} {$limitingName} @ {$runway['limiting_fuel_consumption_per_hour']}/h"
                : '';

            $posLines[$ts] = "**{$typeName}** - {$location} - <t:{$ts}:F> (<t:{$ts}:R>){$detail_str}";
        }
        ksort($posLines);

        if ($debug) {
            $this->line('<info>[DEBUG]</info> online POS lines: ' . count($posLines));
            $this->line('<info>[DEBUG]</info> offline POS lines: ' . count($offlinePosLines));
            $this->line('<info>[DEBUG]</info> upwell lines: ' . count($upwellLines));
            $this->line('<info>[DEBUG]</info> Skipping Discord post in debug mode.');
            return Command::SUCCESS;
        }

        // ── Build Discord embed ──────────────────────────────────────────────
        $hasUpwell = !empty($upwellLines);
        $hasPos    = !empty($posLines) || !empty($offlinePosLines);

        if (!$hasUpwell && !$hasPos) {
            $message = [
                'content' => '@here',
                'embeds'  => [[
                    'title'       => 'No Structures Found',
                    'description' => 'There are no structures to report at this time. Check CEO authentication.',
                    'color'       => 15158332,
                ]],
            ];

            $this->sendToDiscord($messageUrl, $botToken, $message);
            return Command::SUCCESS;
        }

        $fields = [];

        if ($hasUpwell) {
            $fields[] = [
                'name'   => 'Upwell Structures',
                'value'  => $this->splitToFieldValue($upwellLines),
                'inline' => false,
            ];
        }

        if (!empty($posLines)) {
            $fields[] = [
                'name'   => 'POS Fuel (online)',
                'value'  => $this->splitToFieldValue($posLines),
                'inline' => false,
            ];
        }

        if (!empty($offlinePosLines)) {
            $fields[] = [
                'name'   => 'POS (not online)',
                'value'  => $this->splitToFieldValue($offlinePosLines),
                'inline' => false,
            ];
        }

        $message = [
            'embeds' => [[
                'fields' => $fields,
                'color'  => 3447003,
            ]],
        ];

        $this->sendToDiscord($messageUrl, $botToken, $message);

        return Command::SUCCESS;
    }

    /**
     * Collapse an array of lines into a single string, capped at 1024 chars
     * (Discord embed field value limit).
     */
    private function splitToFieldValue(array $lines): string
    {
        $value = implode("\n", array_values($lines));

        if (strlen($value) <= 1024) {
            return $value;
        }

        // Truncate gracefully.
        $truncated = substr($value, 0, 1020) . "\n…";
        return $truncated;
    }

    /**
     * Post a message payload to a Discord channel and log failures.
     */
    private function sendToDiscord(string $url, string $botToken, array $message): void
    {
        $response = Http::withHeaders([
            'Authorization' => "Bot {$botToken}",
            'Content-Type'  => 'application/json',
        ])->post($url, $message);

        if (!$response->successful()) {
            Log::error('EveDiscordStructuresSummary: Discord API error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            $this->error("Discord returned HTTP {$response->status()}: {$response->body()}");
        }
    }
}
