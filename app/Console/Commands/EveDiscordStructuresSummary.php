<?php

namespace App\Console\Commands;

use App\Models\EveCharacter;
use App\Services\EveCorporationAccessResolver;
use App\Services\EveMetenoxFuelCalculator;
use App\Services\EveOnlineProvider;
use App\Services\EvePosFuelCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EveDiscordStructuresSummary extends Command
{
    protected $signature = 'eve:discord:structures-summary {--debug : Dump the generated report instead of posting to Discord}';

    protected $description = 'Post a weekly fuel-expiry summary for every configured operational corporation';

    public function handle(
        EveOnlineProvider $provider,
        EveCorporationAccessResolver $access,
        EvePosFuelCalculator $posCalculator,
        EveMetenoxFuelCalculator $metenoxCalculator
    ): int {
        $corporationIds = config('eve.corporations.operational', []);

        if ($corporationIds === []) {
            $this->error('No operational EVE corporations are configured.');

            return Command::FAILURE;
        }

        $embeds = [];
        $accessibleCorporations = 0;

        foreach ($corporationIds as $corporationId) {
            $report = $this->buildCorporationReport(
                (int) $corporationId,
                $provider,
                $access,
                $posCalculator,
                $metenoxCalculator
            );

            $embeds = [...$embeds, ...$report['embeds']];
            $accessibleCorporations += $report['accessible'] ? 1 : 0;
        }

        if ($this->option('debug')) {
            $this->line(json_encode(['embeds' => $embeds], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $accessibleCorporations > 0 ? Command::SUCCESS : Command::FAILURE;
        }

        $messageUrl = $this->discordMessageUrl();

        if ($messageUrl === null) {
            return Command::FAILURE;
        }

        foreach ($this->batchEmbeds($embeds) as $batch) {
            if (! $this->sendToDiscord($messageUrl, env('DISCORD_BOT_TOKEN'), ['embeds' => $batch])) {
                return Command::FAILURE;
            }
        }

        return $accessibleCorporations > 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return array{embeds: array<int, array>, accessible: bool}
     */
    private function buildCorporationReport(
        int $corporationId,
        EveOnlineProvider $provider,
        EveCorporationAccessResolver $access,
        EvePosFuelCalculator $posCalculator,
        EveMetenoxFuelCalculator $metenoxCalculator
    ): array {
        $corporation = $provider->getCorporationData($corporationId);
        $label = $corporation
            ? "{$corporation['name']} [{$corporation['ticker']}]"
            : "Corporation #{$corporationId}";

        $director = $access->director($corporationId);
        $structureActor = $director ?? $access->structureManager($corporationId);
        $statusLines = [];
        $accessible = false;

        if ($structureActor === null) {
            $statusLines[] = 'Upwell structures unavailable: no linked CEO, Director, or Station Manager with valid scopes.';
            $structures = null;
        } else {
            $structures = $provider->getCorporationStructures($corporationId, $structureActor);
            $accessible = $structures !== null;

            if ($structures === null) {
                $statusLines[] = "Upwell structures unavailable using {$structureActor->name}.";
            }
        }

        $structures ??= [];
        $drills = array_values(array_filter(
            $structures,
            fn (array $structure): bool => (int) ($structure['type_id'] ?? 0) === EveMetenoxFuelCalculator::STRUCTURE_TYPE_ID
        ));

        $corporationAssets = [];
        if ($drills !== []) {
            if ($director === null) {
                $corporationAssets = null;
                $statusLines[] = 'Metenox magma unavailable: no linked CEO or Director.';
            } else {
                $corporationAssets = $provider->getCorporationAssets($corporationId, $director);
                $accessible = $accessible || $corporationAssets !== null;

                if ($corporationAssets === null) {
                    $statusLines[] = 'Metenox magma unavailable: corporation assets could not be read.';
                }
            }
        }

        [$upwellLines, $drillLines] = $this->formatUpwellStructures(
            $structures,
            $corporationAssets,
            $metenoxCalculator
        );

        if ($director === null) {
            $starbases = null;
            $statusLines[] = 'POS unavailable: no linked CEO or Director.';
        } else {
            $starbases = $provider->getCorporationStarbases($corporationId, $director);
            $accessible = $accessible || $starbases !== null;

            if ($starbases === null) {
                $statusLines[] = 'POS unavailable: starbases could not be read.';
            }
        }

        [$posLines, $offlinePosLines] = $this->formatStarbases(
            $corporationId,
            $starbases ?? [],
            $director,
            $provider,
            $posCalculator
        );

        $fields = [
            ...$this->linesToFields('🏠 Upwell Structures', $upwellLines),
            ...$this->linesToFields(
                '⛏️ Metenox Drills · ⛽ '.EveMetenoxFuelCalculator::FUEL_BLOCKS_PER_HOUR
                    .'/h · 🌋 '.EveMetenoxFuelCalculator::MAGMATIC_GAS_PER_HOUR.'/h',
                $drillLines
            ),
            ...$this->linesToFields('🔵 POS', $posLines),
            ...$this->linesToFields('🔵 POS (not online)', $offlinePosLines),
            ...$this->linesToFields('⚠️ Access status', $statusLines),
        ];

        if ($fields === []) {
            $fields[] = [
                'name' => 'Status',
                'value' => 'No structures found.',
                'inline' => false,
            ];
        }

        return [
            'embeds' => $this->fieldsToEmbeds($label, $corporationId, $fields),
            'accessible' => $accessible,
        ];
    }

    /**
     * @return array{array<int, string>, array<int, string>}
     */
    private function formatUpwellStructures(
        array $structures,
        ?array $corporationAssets,
        EveMetenoxFuelCalculator $calculator
    ): array {
        $upwellRows = [];
        $drillRows = [];

        foreach ($structures as $structure) {
            if ((int) ($structure['type_id'] ?? 0) === EveMetenoxFuelCalculator::STRUCTURE_TYPE_ID) {
                $gasRunway = $calculator->calculateMagmaticGasRunway(
                    (int) $structure['structure_id'],
                    $corporationAssets
                );
                $fuelExpires = empty($structure['fuel_expires']) ? null : Carbon::parse($structure['fuel_expires']);
                $gasExpires = $gasRunway['expires_at'];
                $gasQuantity = $gasRunway['quantity'] === null ? '' : ' · '.number_format($gasRunway['quantity']);

                $drillRows[] = [
                    'sort_at' => min(
                        $fuelExpires?->timestamp ?? PHP_INT_MAX,
                        $gasExpires?->timestamp ?? PHP_INT_MAX
                    ),
                    'line' => "**{$structure['name']}**\n"
                        .'⛽ Fuel · '.($fuelExpires ? "<t:{$fuelExpires->timestamp}:F>" : '*unknown*')."\n"
                        .'🌋 Magma'.$gasQuantity.' · '.($gasExpires ? "<t:{$gasExpires->timestamp}:F>" : '*unknown*'),
                ];

                continue;
            }

            if (! empty($structure['fuel_expires'])) {
                $timestamp = Carbon::parse($structure['fuel_expires'])->timestamp;
                $upwellRows[] = [
                    'sort_at' => $timestamp,
                    'line' => "**{$structure['name']}** - <t:{$timestamp}:F>",
                ];
            }
        }

        $this->sortExpiryRows($upwellRows);
        $this->sortExpiryRows($drillRows);

        return [array_column($upwellRows, 'line'), array_column($drillRows, 'line')];
    }

    /**
     * @return array{array<int, string>, array<int, string>}
     */
    private function formatStarbases(
        int $corporationId,
        array $starbases,
        ?EveCharacter $director,
        EveOnlineProvider $provider,
        EvePosFuelCalculator $calculator
    ): array {
        if ($starbases === [] || $director === null) {
            return [[], []];
        }

        $idsToResolve = [];
        $moonIds = [];
        $details = [];

        foreach ($starbases as $starbase) {
            $idsToResolve[] = (int) $starbase['type_id'];
            $idsToResolve[] = (int) $starbase['system_id'];

            if (isset($starbase['moon_id'])) {
                $moonIds[] = (int) $starbase['moon_id'];
            }

            if (($starbase['state'] ?? '') === 'online') {
                $detail = $provider->getCorporationStarbaseDetail(
                    $corporationId,
                    $director,
                    (int) $starbase['starbase_id'],
                    (int) $starbase['system_id']
                );
                $details[$starbase['starbase_id']] = $detail;

                foreach ($detail['fuels'] ?? [] as $fuel) {
                    $idsToResolve[] = (int) $fuel['type_id'];
                }
            }
        }

        $names = $provider->resolveNames($idsToResolve);

        foreach (array_unique($moonIds) as $moonId) {
            if (($moonName = $provider->resolveMoonName($moonId)) !== null) {
                $names[$moonId] = $moonName;
            }
        }

        $onlineRows = [];
        $offlineLines = [];

        foreach ($starbases as $starbase) {
            $state = $starbase['state'] ?? 'offline';
            $typeId = (int) $starbase['type_id'];
            $systemId = (int) $starbase['system_id'];
            $moonId = isset($starbase['moon_id']) ? (int) $starbase['moon_id'] : null;
            $typeName = $names[$typeId] ?? "Type #{$typeId}";
            $location = $moonId
                ? ($names[$moonId] ?? "Moon #{$moonId}")
                : ($names[$systemId] ?? "System #{$systemId}");

            if ($state !== 'online') {
                $offlineLines[] = "**{$typeName}** - {$location} *({$state})*";

                continue;
            }

            $detail = $details[$starbase['starbase_id']] ?? null;
            if ($detail === null) {
                $onlineRows[] = [
                    'sort_at' => PHP_INT_MAX,
                    'line' => "**{$typeName}** - {$location} - *fuel unavailable*",
                ];

                continue;
            }

            $runway = $calculator->calculateRunway($typeId, $detail['fuels'] ?? []);
            if ($runway['fuel_expires'] === null) {
                $onlineRows[] = [
                    'sort_at' => PHP_INT_MAX,
                    'line' => "**{$typeName}** - {$location} - *fuel unknown*",
                ];

                continue;
            }

            $timestamp = $runway['fuel_expires']->timestamp;
            $limitingName = $runway['limiting_fuel_type_id']
                ? ($names[$runway['limiting_fuel_type_id']] ?? "Type #{$runway['limiting_fuel_type_id']}")
                : null;
            $detailText = $limitingName
                ? " - {$runway['limiting_fuel_quantity']} {$limitingName} @ {$runway['limiting_fuel_consumption_per_hour']}/h"
                : '';

            $onlineRows[] = [
                'sort_at' => $timestamp,
                'line' => "**{$typeName}** - {$location} - <t:{$timestamp}:F>{$detailText}",
            ];
        }

        $this->sortExpiryRows($onlineRows);
        sort($offlineLines, SORT_NATURAL | SORT_FLAG_CASE);

        return [array_column($onlineRows, 'line'), $offlineLines];
    }

    private function linesToFields(string $name, array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $fields = [];
        $value = '';
        $part = 1;

        foreach ($lines as $line) {
            $candidate = $value === '' ? $line : "{$value}\n{$line}";

            if (strlen($candidate) > 1024 && $value !== '') {
                $fields[] = [
                    'name' => $part === 1 ? $name : "{$name} (cont.)",
                    'value' => $value,
                    'inline' => false,
                ];
                $value = $line;
                $part++;
            } else {
                $value = $candidate;
            }
        }

        if ($value !== '') {
            $fields[] = [
                'name' => $part === 1 ? $name : "{$name} (cont.)",
                'value' => substr($value, 0, 1024),
                'inline' => false,
            ];
        }

        return $fields;
    }

    private function fieldsToEmbeds(string $label, int $corporationId, array $fields): array
    {
        $embeds = [];
        $current = [];
        $characters = 0;
        $part = 1;

        foreach ($fields as $field) {
            $fieldCharacters = strlen($field['name']) + strlen($field['value']);

            if ($current !== [] && (count($current) >= 25 || $characters + $fieldCharacters > 5500)) {
                $embeds[] = $this->makeEmbed($label, $corporationId, $current, $part++);
                $current = [];
                $characters = 0;
            }

            $current[] = $field;
            $characters += $fieldCharacters;
        }

        if ($current !== []) {
            $embeds[] = $this->makeEmbed($label, $corporationId, $current, $part);
        }

        return $embeds;
    }

    private function makeEmbed(string $label, int $corporationId, array $fields, int $part): array
    {
        return [
            'title' => $part === 1 ? $label : "{$label} (cont.)",
            'url' => "https://zkillboard.com/corporation/{$corporationId}/",
            'fields' => $fields,
            'color' => 3447003,
        ];
    }

    private function batchEmbeds(array $embeds): array
    {
        $batches = [];
        $current = [];
        $characters = 0;

        foreach ($embeds as $embed) {
            $embedCharacters = strlen($embed['title'])
                + array_sum(array_map(
                    fn (array $field): int => strlen($field['name']) + strlen($field['value']),
                    $embed['fields']
                ));

            if ($current !== [] && (count($current) >= 10 || $characters + $embedCharacters > 5800)) {
                $batches[] = $current;
                $current = [];
                $characters = 0;
            }

            $current[] = $embed;
            $characters += $embedCharacters;
        }

        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    private function sortExpiryRows(array &$rows): void
    {
        usort($rows, fn (array $a, array $b): int => ($a['sort_at'] <=> $b['sort_at'])
            ?: strnatcasecmp($a['line'], $b['line']));
    }

    private function discordMessageUrl(): ?string
    {
        $baseUrl = 'https://discord.com/api/v10';
        $botToken = env('DISCORD_BOT_TOKEN');
        $guildId = env('DISCORD_SERVER_ID');
        $channelName = env('DISCORD_BROADCAST_CHANNEL');

        if (! $channelName) {
            $this->error('Please set DISCORD_BROADCAST_CHANNEL.');

            return null;
        }

        $response = Http::withHeaders(['Authorization' => "Bot {$botToken}"])
            ->get("{$baseUrl}/guilds/{$guildId}/channels");

        if (! $response->successful()) {
            $this->error("Discord API error fetching channels: HTTP {$response->status()}.");

            return null;
        }

        $channel = collect($response->json())->firstWhere('name', $channelName);

        if (! $channel) {
            $this->error("No #{$channelName} channel found.");

            return null;
        }

        return "{$baseUrl}/channels/{$channel['id']}/messages";
    }

    private function sendToDiscord(string $url, string $botToken, array $message): bool
    {
        $response = Http::withHeaders([
            'Authorization' => "Bot {$botToken}",
            'Content-Type' => 'application/json',
        ])->post($url, $message);

        if ($response->successful()) {
            return true;
        }

        Log::error('EveDiscordStructuresSummary: Discord API error', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        $this->error("Discord returned HTTP {$response->status()}: {$response->body()}");

        return false;
    }
}
