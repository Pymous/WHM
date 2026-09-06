<?php

namespace App\Services;

use App\Models\EvePiTaxEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class EvePiTaxSyncService
{
    private const PI_REFERENCE_TYPES = [
        'planetary_export_tax',
        'planetary_import_tax',
    ];

    public function __construct(
        private readonly EveOnlineProvider $provider,
        private readonly EveCorporationAccessResolver $access,
    ) {}

    /**
     * @return array{fetched: int, stored: int, divisions: int, pages: int, actor: string}
     */
    public function sync(int $corporationId): array
    {
        $actor = $this->access->director($corporationId, [
            'esi-wallet.read_corporation_wallets.v1',
        ]);

        if (! $actor) {
            throw new RuntimeException("No Director with valid ESI scopes is available for corporation {$corporationId}.");
        }

        $entries = [];
        $pageCount = 0;

        foreach (range(1, 7) as $division) {
            $page = 1;

            do {
                $result = $this->provider->getCorporationWalletJournalPage(
                    $corporationId,
                    $division,
                    $actor,
                    $page,
                );

                if ($result === null) {
                    throw new RuntimeException("Unable to read wallet division {$division}, page {$page}.");
                }

                $pageCount++;

                foreach ($result['entries'] as $entry) {
                    if (in_array($entry['ref_type'] ?? null, self::PI_REFERENCE_TYPES, true)) {
                        $entry['_wallet_division'] = $division;
                        $entries[(string) $entry['id']] = $entry;
                    }
                }

                $page++;
            } while ($page <= $result['pages']);
        }

        if ($entries === []) {
            return [
                'fetched' => 0,
                'stored' => 0,
                'divisions' => 7,
                'pages' => $pageCount,
                'actor' => $actor->name,
            ];
        }

        $payerIds = [];
        $planetIds = [];

        foreach ($entries as $entry) {
            $payerId = $this->payerId($entry, $corporationId);

            if ($payerId) {
                $payerIds[] = $payerId;
            }

            if (($entry['context_id_type'] ?? null) === 'planet_id' && ! empty($entry['context_id'])) {
                $planetIds[] = (int) $entry['context_id'];
            }
        }

        $payerNames = $this->provider->resolveNames($payerIds);
        $planetNames = [];

        foreach (array_unique($planetIds) as $planetId) {
            $planetNames[$planetId] = Cache::remember(
                "eve:planet:{$planetId}:name",
                now()->addDays(30),
                fn (): ?string => $this->provider->resolvePlanetName($planetId),
            );
        }

        $now = now();
        $rows = [];

        foreach ($entries as $entry) {
            $payerId = $this->payerId($entry, $corporationId);
            $planetId = ($entry['context_id_type'] ?? null) === 'planet_id'
                ? (int) ($entry['context_id'] ?? 0) ?: null
                : null;

            $rows[] = [
                'corporation_id' => $corporationId,
                'journal_id' => (int) $entry['id'],
                'wallet_division' => (int) $entry['_wallet_division'],
                'ref_type' => $entry['ref_type'],
                'amount' => $entry['amount'],
                'balance' => $entry['balance'] ?? null,
                'occurred_at' => CarbonImmutable::parse($entry['date'])->utc()->format('Y-m-d H:i:s'),
                'first_party_id' => $entry['first_party_id'] ?? null,
                'second_party_id' => $entry['second_party_id'] ?? null,
                'payer_id' => $payerId,
                'payer_name' => $payerId ? ($payerNames[$payerId] ?? null) : null,
                'context_id' => $entry['context_id'] ?? null,
                'context_id_type' => $entry['context_id_type'] ?? null,
                'planet_id' => $planetId,
                'planet_name' => $planetId ? ($planetNames[$planetId] ?? null) : null,
                'description' => $entry['description'] ?? null,
                'reason' => $entry['reason'] ?? null,
                'tax' => $entry['tax'] ?? null,
                'tax_receiver_id' => $entry['tax_receiver_id'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            EvePiTaxEntry::upsert(
                $chunk,
                ['corporation_id', 'journal_id'],
                [
                    'wallet_division',
                    'ref_type',
                    'amount',
                    'balance',
                    'occurred_at',
                    'first_party_id',
                    'second_party_id',
                    'payer_id',
                    'payer_name',
                    'context_id',
                    'context_id_type',
                    'planet_id',
                    'planet_name',
                    'description',
                    'reason',
                    'tax',
                    'tax_receiver_id',
                    'updated_at',
                ],
            );
        }

        return [
            'fetched' => count($entries),
            'stored' => count($rows),
            'divisions' => 7,
            'pages' => $pageCount,
            'actor' => $actor->name,
        ];
    }

    private function payerId(array $entry, int $corporationId): ?int
    {
        $firstPartyId = (int) ($entry['first_party_id'] ?? 0);
        $secondPartyId = (int) ($entry['second_party_id'] ?? 0);
        $payerId = $firstPartyId === $corporationId ? $secondPartyId : $firstPartyId;

        return $payerId > 0 && $payerId !== $corporationId ? $payerId : null;
    }
}
