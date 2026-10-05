<?php

namespace App\Integrations\Tally;

use App\Enums\Integrations\TallySyncStatus;
use App\Models\Integrations\TallyConnection;
use App\Models\Integrations\TallySyncRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class TallyStatusPresenter
{
    public function __construct(private readonly TallyDocumentRegistry $documents) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(Model $model): ?array
    {
        $user = Auth::user();
        if ($user === null || ! $user->can('tally.view')) {
            return null;
        }

        $connection = TallyConnection::query()->first();
        $record = TallySyncRecord::query()
            ->where('source_type', $model->getMorphClass())
            ->where('source_id', $model->getKey())
            ->where('action', 'export')
            ->first();
        if (! $connection?->enabled && $record === null) {
            return null;
        }

        $status = $record?->status ?? TallySyncStatus::NotSynced;
        $eligible = $this->documents->canExport($model);

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'error' => $record?->error_message,
            'reference' => $record?->tally_guid ?: $record?->tally_alter_id,
            'eligible' => $eligible,
            'can_preview' => $eligible && $user->can('tally.sync'),
            'can_sync' => $eligible && $connection?->enabled && $user->can('tally.sync') && $status !== TallySyncStatus::Synced,
            'can_retry' => $connection?->enabled && $user->can('tally.retry') && in_array($status, [TallySyncStatus::Failed, TallySyncStatus::NeedsMapping, TallySyncStatus::Pending], true),
            'log_url' => $record && $user->can('tally.view') ? route('integrations.tally.history.show', $record) : null,
            'preview_url' => route('integrations.tally.documents.preview', [$model->getMorphClass(), $model->getKey()]),
            'sync_url' => route('integrations.tally.documents.sync', [$model->getMorphClass(), $model->getKey()]),
            'retry_url' => $record ? route('integrations.tally.history.retry', $record) : null,
        ];
    }
}
