<?php

namespace App\Integrations\Tally;

use App\Enums\Integrations\TallySyncStatus;
use App\Jobs\Tally\SyncTallyTransaction;
use App\Models\Integrations\TallyConnection;
use App\Models\Integrations\TallySyncRecord;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Queues and delivers one accounting voucher. It writes only tally_sync_records.
 * It does not create invoices, bills, payments, stock rows or project-cost entries.
 */
class TallySyncService
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly TallyDocumentRegistry $documents,
        private readonly TallyClientFactory $clients,
        private readonly TallyXmlBuilder $xml,
        private readonly TallyResponseParser $parser,
        private readonly AuditLogger $audit,
    ) {}

    public function preview(Model $model): TallyVoucher
    {
        $this->requireConnection();
        if (! $this->documents->canExport($model)) {
            throw ValidationException::withMessages(['tally' => 'Only a finalized document can be previewed.']);
        }

        return $this->documents->voucher($model, $this->requireConnection());
    }

    public function enqueue(Model $model, string $action = 'export', bool $manual = false): TallySyncRecord
    {
        $connection = $this->requireConnection();
        $allowed = $action === 'cancel' ? $this->documents->canCancel($model) : $this->documents->canExport($model);
        if (! $allowed) {
            throw ValidationException::withMessages(['tally' => 'Only a finalized document can be synced.']);
        }

        $meta = $this->documents->meta($model);
        $existing = $this->record($meta['type'], (int) $model->getKey(), $action);

        if ($action === 'export' && $existing?->status === TallySyncStatus::Synced) {
            $voucher = $this->documents->voucher($model, $connection);
            if ($existing->request_hash === $voucher->hash()) {
                return $existing;
            }
            $existing->forceFill([
                'status' => TallySyncStatus::Conflict,
                'error_code' => 'changed',
                'error_message' => 'Previously synced document has changed',
            ])->save();

            return $existing;
        }

        $record = $existing ?? new TallySyncRecord;
        $record->forceFill([
            'source_type' => $meta['type'],
            'source_id' => $model->getKey(),
            'action' => $action,
            'erp_reference' => $meta['reference'],
            'project_id' => $meta['project_id'],
            'document_date' => $meta['date'],
            'amount' => $meta['amount'],
            'status' => $action === 'cancel' ? TallySyncStatus::CancelPending : TallySyncStatus::Pending,
            'error_code' => null,
            'error_message' => null,
            'created_by' => $record->exists ? $record->created_by : Auth::id(),
        ])->save();

        if ($manual) {
            $this->audit->record($record, $action === 'cancel' ? 'tally_reversal_requested' : 'tally_sync_requested', null, [
                'source_type' => $record->source_type,
                'source_id' => $record->source_id,
                'erp_reference' => $record->erp_reference,
            ]);
        }

        SyncTallyTransaction::dispatch($record->id);

        return $record->fresh();
    }

    /**
     * @param  array{project_id?: int|null, from?: string|null, to?: string|null, type?: string|null, status?: string|null}  $filters
     */
    public function enqueuePending(array $filters): int
    {
        $this->requireConnection();
        $queued = 0;
        foreach ($this->documents->candidates($filters) as $model) {
            if ($queued >= 50) {
                break;
            }
            $meta = $this->documents->meta($model);
            if (($filters['from'] ?? null) && $meta['date'] < $filters['from']) {
                continue;
            }
            if (($filters['to'] ?? null) && $meta['date'] > $filters['to']) {
                continue;
            }
            if (! empty($filters['project_id']) && (int) $meta['project_id'] !== (int) $filters['project_id']) {
                continue;
            }
            $existing = $this->record($meta['type'], (int) $model->getKey(), 'export');
            if ($existing?->status === TallySyncStatus::Synced) {
                continue;
            }
            if (($filters['status'] ?? null) && ($existing?->status->value ?? 'not_synced') !== $filters['status']) {
                continue;
            }
            $this->enqueue($model);
            $queued++;
        }

        return $queued;
    }

    public function retry(TallySyncRecord $record): TallySyncRecord
    {
        if (! in_array($record->status, [TallySyncStatus::Failed, TallySyncStatus::Pending, TallySyncStatus::NeedsMapping, TallySyncStatus::CancelPending], true)) {
            throw ValidationException::withMessages(['tally' => 'This sync cannot be retried.']);
        }
        $record->forceFill([
            'attempts' => 0,
            'status' => $record->action === 'cancel' ? TallySyncStatus::CancelPending : TallySyncStatus::Pending,
            'error_code' => null,
            'error_message' => null,
        ])->save();
        $this->audit->record($record, 'tally_retry', null, ['erp_reference' => $record->erp_reference]);
        SyncTallyTransaction::dispatch($record->id);

        return $record;
    }

    /** @return 'done'|'retry' */
    public function deliver(int $recordId): string
    {
        $record = TallySyncRecord::query()->find($recordId);
        if ($record === null || in_array($record->status, [TallySyncStatus::Synced, TallySyncStatus::ReversalSynced, TallySyncStatus::Conflict], true)) {
            return 'done';
        }

        $connection = TallyConnection::query()->first();
        $model = $connection ? $this->documents->find($record->source_type, (int) $record->source_id) : null;
        if (! $connection?->enabled || $model === null) {
            $this->mark($record, TallySyncStatus::Failed, 'unavailable', 'Tally integration is turned off, or the document is no longer available.');

            return 'done';
        }

        try {
            $voucher = $this->documents->voucher($model, $connection);
        } catch (MissingTallyMappingException $exception) {
            $this->mark($record, TallySyncStatus::NeedsMapping, 'needs_mapping', $exception->getMessage());

            return 'done';
        } catch (TallyException $exception) {
            $this->mark($record, TallySyncStatus::Failed, 'unbalanced', $exception->getMessage());

            return 'done';
        }

        $payload = [
            'voucher_type' => $voucher->voucherType,
            'request_hash' => $voucher->hash(),
            'request_payload' => $voucher->toArray(),
            'amount' => $record->amount,
        ];

        if ($connection->dry_run) {
            $record->forceFill($payload + [
                'status' => TallySyncStatus::Pending,
                'error_code' => 'dry_run',
                'error_message' => 'Dry run only. Nothing was sent to Tally.',
            ])->save();

            return 'done';
        }

        try {
            $body = $this->clients->make($connection)->postXml(
                $this->xml->voucher($voucher, (string) $connection->tally_company_name, $record->action === 'cancel' ? 'Cancel' : 'Create'),
            );
            $parsed = $this->parser->import($body);
        } catch (TallyTransportException $exception) {
            $attempts = $record->attempts + 1;
            $giveUp = $attempts >= self::MAX_ATTEMPTS;
            $record->forceFill($payload + [
                'attempts' => $attempts,
                'status' => $giveUp
                    ? TallySyncStatus::Failed
                    : ($record->action === 'cancel' ? TallySyncStatus::CancelPending : TallySyncStatus::Pending),
                'error_code' => $exception->reason,
                'error_message' => $exception->getMessage(),
            ])->save();

            return $giveUp ? 'done' : 'retry';
        } catch (TallyResponseException $exception) {
            $this->mark($record, TallySyncStatus::Failed, $exception->reason, $exception->getMessage(), $payload);

            return 'done';
        }

        if (! $this->parser->importSucceeded($parsed)) {
            $message = $parsed['line_errors'][0] ?? 'Tally rejected the voucher.';
            $duplicate = $this->alreadyExists($message);
            $this->mark(
                $record,
                $duplicate ? TallySyncStatus::Conflict : TallySyncStatus::Failed,
                $duplicate ? 'duplicate' : 'import_error',
                $duplicate ? 'A Tally voucher with this reference already exists. It was not created again.' : $message,
                $payload + ['response_payload' => $parsed],
            );

            return 'done';
        }

        $record->forceFill($payload + [
            'status' => $record->action === 'cancel' ? TallySyncStatus::ReversalSynced : TallySyncStatus::Synced,
            'attempts' => $record->attempts + 1,
            'tally_guid' => $parsed['guid'],
            'tally_master_id' => $parsed['last_mid'],
            'tally_alter_id' => $parsed['last_vch_id'],
            'response_payload' => $parsed,
            'error_code' => null,
            'error_message' => null,
            'synced_at' => now(),
        ])->save();
        $connection->forceFill(['last_sync_at' => now()])->save();

        return 'done';
    }

    private function requireConnection(): TallyConnection
    {
        $connection = TallyConnection::query()->first();
        if (! $connection?->enabled) {
            throw ValidationException::withMessages(['tally' => 'Tally integration is turned off.']);
        }

        return $connection;
    }

    private function record(string $type, int $id, string $action): ?TallySyncRecord
    {
        return TallySyncRecord::query()
            ->where('source_type', $type)
            ->where('source_id', $id)
            ->where('action', $action)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function mark(TallySyncRecord $record, TallySyncStatus $status, string $code, string $message, array $extra = []): void
    {
        $record->forceFill($extra + [
            'status' => $status,
            'attempts' => $record->attempts + 1,
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 500),
        ])->save();
    }

    private function alreadyExists(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'already') || str_contains($lower, 'duplicate') || str_contains($lower, 'exists');
    }
}
