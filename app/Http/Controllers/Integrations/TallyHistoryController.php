<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\Tally\TallyReconciliationService;
use App\Integrations\Tally\TallySyncService;
use App\Models\Integrations\TallySyncRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TallyHistoryController extends Controller
{
    public function __construct(
        private readonly TallySyncService $sync,
        private readonly TallyReconciliationService $reconciliation,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('tally.view'), 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'project_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);
        $reference = isset($filters['reference']) ? addcslashes($filters['reference'], '%_') : null;

        $records = TallySyncRecord::query()
            ->with('project:id,code')
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('document_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('document_date', '<=', $to))
            ->when($filters['project_id'] ?? null, fn ($query, $project) => $query->where('project_id', $project))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('source_type', $type))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($reference, fn ($query) => $query->where('erp_reference', 'like', '%'.$reference.'%'))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (TallySyncRecord $record) => [
                'id' => $record->id,
                'date' => $record->document_date?->toDateString(),
                'reference' => $record->erp_reference,
                'project' => $record->project?->code,
                'type' => $record->source_type,
                'voucher_type' => $record->voucher_type,
                'amount' => $record->amount,
                'status' => $record->status->value,
                'status_label' => $record->status->label(),
                'attempted_at' => $record->updated_at?->toIso8601String(),
                'tally_reference' => $record->tally_guid ?: $record->tally_alter_id,
                'error' => $record->error_message,
            ]);

        return Inertia::render('Integrations/Tally/History', [
            'records' => $records,
            'filters' => $filters,
            'can' => ['retry' => $request->user()->can('tally.retry')],
        ]);
    }

    public function show(Request $request, TallySyncRecord $record): Response
    {
        abort_unless($request->user()->can('tally.view'), 403);
        $manage = $request->user()->can('tally.manage');

        return Inertia::render('Integrations/Tally/Show', [
            'record' => [
                'id' => $record->id,
                'source_type' => $record->source_type,
                'source_id' => $record->source_id,
                'action' => $record->action,
                'reference' => $record->erp_reference,
                'voucher_type' => $record->voucher_type,
                'amount' => $record->amount,
                'status' => $record->status->value,
                'status_label' => $record->status->label(),
                'attempts' => $record->attempts,
                'lines' => $record->request_payload['lines'] ?? [],
                'narration' => $record->request_payload['narration'] ?? null,
                'party_ledger' => $record->request_payload['party_ledger'] ?? null,
                'response' => $manage ? $record->response_payload : null,
                'error' => $record->error_message,
                'tally_reference' => $record->tally_guid ?: $record->tally_alter_id,
                'synced_at' => $record->synced_at?->toIso8601String(),
                'updated_at' => $record->updated_at?->toIso8601String(),
            ],
            'can' => ['retry' => $request->user()->can('tally.retry'), 'manage' => $manage],
        ]);
    }

    public function retry(Request $request, TallySyncRecord $record): RedirectResponse
    {
        abort_unless($request->user()->can('tally.retry'), 403);
        $this->sync->retry($record);

        return back()->with('success', 'Tally sync queued again.');
    }

    public function reconciliation(Request $request): Response
    {
        abort_unless($request->user()->can('tally.view'), 403);

        return Inertia::render('Integrations/Tally/Reconciliation', $this->reconciliation->summary());
    }
}
