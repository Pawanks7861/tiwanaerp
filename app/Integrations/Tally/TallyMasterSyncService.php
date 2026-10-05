<?php

namespace App\Integrations\Tally;

use App\Models\Crm\Client;
use App\Models\Integrations\TallyLedgerMapping;
use App\Models\Integrations\TallyMasterMapping;
use App\Models\Masters\ExpenseCategory;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\Vendor;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Pushes masters that already have a confirmed ledger name. It never invents "ABC Ltd 1".
 */
class TallyMasterSyncService
{
    public function __construct(
        private readonly TallyClientFactory $clients,
        private readonly TallyXmlBuilder $xml,
        private readonly TallyResponseParser $parser,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{synced: int, conflicts: int, skipped: int}
     */
    public function syncMappedMasters(): array
    {
        $connection = \App\Models\Integrations\TallyConnection::query()->first();
        if (! $connection?->enabled) {
            throw ValidationException::withMessages(['tally' => 'Tally integration is turned off.']);
        }

        $counts = ['synced' => 0, 'conflicts' => 0, 'skipped' => 0];
        TallyLedgerMapping::query()
            ->where('active', true)
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(function (TallyLedgerMapping $mapping) use ($connection, &$counts) {
                $result = $this->push($connection, $mapping);
                $counts[$result]++;
            });

        return $counts;
    }

    private function push(\App\Models\Integrations\TallyConnection $connection, TallyLedgerMapping $mapping): string
    {
        $source = $this->source($mapping);
        if ($source === null || trim($mapping->tally_ledger_name) === '') {
            return 'skipped';
        }

        $master = TallyMasterMapping::query()->firstOrNew([
            'source_type' => $mapping->source_type,
            'source_id' => $mapping->source_id,
        ]);
        if ($master->exists && $master->status === 'mapped') {
            return 'skipped';
        }

        $sameName = TallyMasterMapping::query()
            ->where('tally_ledger_name', $mapping->tally_ledger_name)
            ->where(fn ($query) => $query->where('source_type', '!=', $mapping->source_type)->orWhere('source_id', '!=', $mapping->source_id))
            ->exists();
        if ($sameName) {
            $this->store($master, $mapping, 'conflict', 'Another master already uses this Tally ledger name.');

            return 'conflicts';
        }

        if (! $mapping->auto_create_allowed) {
            $this->store($master, $mapping, 'mapped', null);

            return 'synced';
        }

        if ($connection->dry_run || $connection->format === 'json') {
            $this->store($master, $mapping, 'pending', 'Dry run or JSON mode did not create a ledger.');

            return 'skipped';
        }

        try {
            $body = $this->clients->make($connection)->postXml($this->xml->ledger([
                'name' => $mapping->tally_ledger_name,
                'parent' => $mapping->tally_parent_group ?: $this->defaultParent($mapping->source_type),
                'address' => $this->address($source),
                'gstin' => (string) ($source->gstin ?? ''),
                'state' => (string) ($source->state_code ?? ''),
                'pan' => (string) ($source->pan ?? ''),
            ], (string) $connection->tally_company_name));
            $parsed = $this->parser->import($body);
        } catch (TallyException $exception) {
            $this->store($master, $mapping, 'conflict', $exception->getMessage());

            return 'conflicts';
        }

        if (! $this->parser->importSucceeded($parsed)) {
            $message = $parsed['line_errors'][0] ?? 'Tally did not create the ledger.';
            $exists = str_contains(strtolower($message), 'exist') || str_contains(strtolower($message), 'duplicate');
            $this->store($master, $mapping, 'conflict', $exists
                ? 'A ledger with this name already exists in Tally. Confirm the mapping before creating another.'
                : $message);

            return 'conflicts';
        }

        $master->forceFill([
            'tally_ledger_name' => $mapping->tally_ledger_name,
            'tally_guid' => $parsed['guid'],
            'status' => 'mapped',
            'message' => null,
        ])->save();
        $this->audit->record($master, 'tally_master_synced', null, [
            'source_type' => $mapping->source_type,
            'source_id' => $mapping->source_id,
            'ledger' => $mapping->tally_ledger_name,
        ]);

        return 'synced';
    }

    private function store(TallyMasterMapping $master, TallyLedgerMapping $mapping, string $status, ?string $message): void
    {
        $master->forceFill([
            'tally_ledger_name' => $mapping->tally_ledger_name,
            'status' => $status,
            'message' => $message ? mb_substr($message, 0, 500) : null,
        ])->save();
    }

    private function source(TallyLedgerMapping $mapping): ?Model
    {
        return match ($mapping->source_type) {
            'client' => Client::query()->find($mapping->source_id),
            'vendor' => Vendor::query()->find($mapping->source_id),
            'subcontractor' => Subcontractor::query()->find($mapping->source_id),
            'expense_category' => ExpenseCategory::query()->find($mapping->source_id),
            default => null,
        };
    }

    private function defaultParent(?string $type): string
    {
        return match ($type) {
            'vendor', 'subcontractor' => 'Sundry Creditors',
            'expense_category' => 'Indirect Expenses',
            default => 'Sundry Debtors',
        };
    }

    private function address(Model $source): string
    {
        $parts = array_filter([
            $source->billing_address ?? $source->address ?? null,
            $source->city ?? null,
            $source->pincode ?? null,
        ]);

        return mb_substr(implode(', ', $parts), 0, 250);
    }
}
