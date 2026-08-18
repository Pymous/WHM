<?php

namespace App\Services;

use App\Models\EvePiTaxEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class EvePiTaxAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function analyze(
        int $corporationId,
        string $range = '7d',
        ?string $customStart = null,
        ?string $customEnd = null,
    ): array {
        [$start, $end] = $this->resolvePeriod($range, $customStart, $customEnd);

        $entries = EvePiTaxEntry::query()
            ->where('corporation_id', $corporationId)
            ->whereBetween('occurred_at', [$start, $end])
            ->orderBy('occurred_at')
            ->get();

        $total = (float) $entries->sum(fn (EvePiTaxEntry $entry): float => (float) $entry->amount);
        $export = (float) $entries
            ->where('ref_type', 'planetary_export_tax')
            ->sum(fn (EvePiTaxEntry $entry): float => (float) $entry->amount);
        $import = (float) $entries
            ->where('ref_type', 'planetary_import_tax')
            ->sum(fn (EvePiTaxEntry $entry): float => (float) $entry->amount);
        $activeTaxpayers = $entries->pluck('payer_id')->filter()->unique()->count();
        $periodDays = $range === 'custom'
            ? $start->startOfDay()->diffInDays($end->startOfDay()) + 1
            : max(1, $start->diffInSeconds($end) / 86400);

        $coverage = EvePiTaxEntry::query()
            ->where('corporation_id', $corporationId)
            ->selectRaw('MIN(occurred_at) as earliest, MAX(occurred_at) as latest, MAX(updated_at) as last_synced')
            ->first();

        return [
            'range' => $range,
            'period' => [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
            ],
            'coverage' => [
                'start' => $coverage?->earliest
                    ? CarbonImmutable::parse($coverage->earliest, 'UTC')->toIso8601String()
                    : null,
                'end' => $coverage?->latest
                    ? CarbonImmutable::parse($coverage->latest, 'UTC')->toIso8601String()
                    : null,
                'last_synced_at' => $coverage?->last_synced
                    ? CarbonImmutable::parse($coverage->last_synced, 'UTC')->toIso8601String()
                    : null,
            ],
            'stats' => [
                'total_revenue' => round($total, 2),
                'average_daily_revenue' => round($total / $periodDays, 2),
                'entries' => $entries->count(),
                'active_taxpayers' => $activeTaxpayers,
                'export_revenue' => round($export, 2),
                'import_revenue' => round($import, 2),
                'export_share' => $total > 0 ? round(($export / $total) * 100, 2) : 0,
                'import_share' => $total > 0 ? round(($import / $total) * 100, 2) : 0,
            ],
            'series' => $this->series($entries, $start, $end),
            'taxpayers' => $this->rankedTaxpayers($entries, $total),
            'planets' => $this->rankedPlanets($entries, $total),
        ];
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function resolvePeriod(string $range, ?string $customStart, ?string $customEnd): array
    {
        $end = CarbonImmutable::now('UTC');

        $start = match ($range) {
            '24h' => $end->subDay(),
            '7d' => $end->subDays(7),
            '3m' => $end->subMonthsNoOverflow(3),
            '1y' => $end->subYear(),
            'custom' => $customStart
                ? CarbonImmutable::parse($customStart, 'UTC')->startOfDay()
                : throw new InvalidArgumentException('A custom start date is required.'),
            default => throw new InvalidArgumentException('Unsupported PI revenue range.'),
        };

        if ($range === 'custom') {
            if (! $customEnd) {
                throw new InvalidArgumentException('A custom end date is required.');
            }

            $end = CarbonImmutable::parse($customEnd, 'UTC')->endOfDay()->min($end);
        }

        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException('The start date must be before the end date.');
        }

        return [$start, $end];
    }

    /**
     * @return array{granularity: string, points: array<int, array{timestamp: string, export: float, import: float, total: float}>}
     */
    private function series(Collection $entries, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $hours = $start->diffInHours($end);
        $days = $start->diffInDays($end);
        $granularity = $hours <= 48 ? 'hour' : ($days <= 120 ? 'day' : 'month');
        $format = match ($granularity) {
            'hour' => 'Y-m-d\TH:00:00\Z',
            'day' => 'Y-m-d',
            default => 'Y-m',
        };

        $buckets = [];
        $cursor = match ($granularity) {
            'hour' => $start->startOfHour(),
            'day' => $start->startOfDay(),
            default => $start->startOfMonth(),
        };
        $lastBucket = match ($granularity) {
            'hour' => $end->startOfHour(),
            'day' => $end->startOfDay(),
            default => $end->startOfMonth(),
        };

        while ($cursor->lessThanOrEqualTo($lastBucket)) {
            $key = $cursor->format($format);
            $buckets[$key] = ['timestamp' => $key, 'export' => 0.0, 'import' => 0.0, 'total' => 0.0];
            $cursor = match ($granularity) {
                'hour' => $cursor->addHour(),
                'day' => $cursor->addDay(),
                default => $cursor->addMonth(),
            };
        }

        foreach ($entries as $entry) {
            $key = $entry->occurred_at->utc()->format($format);

            if (! isset($buckets[$key])) {
                continue;
            }

            $amount = (float) $entry->amount;
            $type = $entry->ref_type === 'planetary_import_tax' ? 'import' : 'export';
            $buckets[$key][$type] += $amount;
            $buckets[$key]['total'] += $amount;
        }

        foreach ($buckets as &$bucket) {
            $bucket['export'] = round($bucket['export'], 2);
            $bucket['import'] = round($bucket['import'], 2);
            $bucket['total'] = round($bucket['total'], 2);
        }

        return [
            'granularity' => $granularity,
            'points' => array_values($buckets),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, amount: float, entries: int, share: float}>
     */
    private function rankedTaxpayers(Collection $entries, float $total): array
    {
        return $entries
            ->filter(fn (EvePiTaxEntry $entry): bool => $entry->payer_id !== null)
            ->groupBy('payer_id')
            ->map(function (Collection $payerEntries, string|int $payerId) use ($total): array {
                $amount = (float) $payerEntries->sum(fn (EvePiTaxEntry $entry): float => (float) $entry->amount);

                return [
                    'id' => (int) $payerId,
                    'name' => $payerEntries->pluck('payer_name')->filter()->last() ?? "Character {$payerId}",
                    'amount' => round($amount, 2),
                    'entries' => $payerEntries->count(),
                    'share' => $total > 0 ? round(($amount / $total) * 100, 2) : 0,
                ];
            })
            ->sortByDesc('amount')
            ->take(15)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, amount: float, entries: int, share: float}>
     */
    private function rankedPlanets(Collection $entries, float $total): array
    {
        return $entries
            ->filter(fn (EvePiTaxEntry $entry): bool => $entry->planet_id !== null)
            ->groupBy('planet_id')
            ->map(function (Collection $planetEntries, string|int $planetId) use ($total): array {
                $amount = (float) $planetEntries->sum(fn (EvePiTaxEntry $entry): float => (float) $entry->amount);

                return [
                    'id' => (int) $planetId,
                    'name' => $planetEntries->pluck('planet_name')->filter()->last() ?? "Planet {$planetId}",
                    'amount' => round($amount, 2),
                    'entries' => $planetEntries->count(),
                    'share' => $total > 0 ? round(($amount / $total) * 100, 2) : 0,
                ];
            })
            ->sortByDesc('amount')
            ->take(15)
            ->values()
            ->all();
    }
}
