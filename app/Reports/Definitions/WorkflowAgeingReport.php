<?php

namespace App\Reports\Definitions;

use App\Enums\Approval\ApprovalStatus;
use App\Models\Approval\ApprovalRequest;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use Illuminate\Support\Carbon;

final class WorkflowAgeingReport extends ReportDefinition
{
    public function key(): string
    {
        return 'workflow-ageing';
    }

    public function title(): string
    {
        return 'Workflow Ageing';
    }

    public function category(): string
    {
        return 'executive';
    }

    public function description(): string
    {
        return 'Pending approval requests with days in current stage and total age.';
    }

    public function permissions(): array
    {
        return ['approvals.view'];
    }

    public function build(ReportContext $ctx, bool $paginate = true): ReportResult
    {
        $today = Carbon::parse($ctx->today->toDateString())->startOfDay();
        $financial = $ctx->user->can('reports.view_financial');

        $requests = ApprovalRequest::query()
            ->with(['workflow:id,document_type,name', 'actions', 'approvable'])
            ->where('company_id', $ctx->companyId())
            ->where('status', ApprovalStatus::Pending)
            ->latest('id')
            ->limit(500)
            ->get()
            ->filter(function (ApprovalRequest $request) use ($ctx) {
                $projectId = method_exists($request->approvable, 'approvalProjectId')
                    ? $request->approvable?->approvalProjectId()
                    : null;

                return $projectId === null || in_array((int) $projectId, array_map('intval', $ctx->projectIds), true);
            })
            ->values();

        $rows = $requests->map(function (ApprovalRequest $request) use ($today, $financial) {
            $lastAction = $request->actions->last();
            $stageEntered = $lastAction?->acted_at ?? $request->submitted_at;
            $document = $request->approvable;
            $title = method_exists($document, 'approvalTitle') ? $document->approvalTitle() : class_basename($document ?? 'Document');
            $amount = method_exists($document, 'approvalAmount') ? $document->approvalAmount() : null;

            return [
                'document_type' => $request->workflow?->document_type ?? $request->approvable_type,
                'document_number' => $title,
                'project' => method_exists($document, 'project') ? $document->project?->name : null,
                'current_stage' => 'Level '.$request->current_level.(filled($request->currentStep()['name'] ?? null) ? ' — '.$request->currentStep()['name'] : ''),
                'status' => $request->status->value,
                'submitted_at' => $request->submitted_at?->toDateTimeString(),
                'stage_entered_at' => $stageEntered?->toDateTimeString(),
                'days_pending' => $stageEntered ? $stageEntered->startOfDay()->diffInDays($today) : 0,
                'total_age' => $request->submitted_at ? $request->submitted_at->startOfDay()->diffInDays($today) : 0,
                'amount' => $financial ? $amount : null,
            ];
        })->all();

        if ($paginate) {
            $page = max(1, (int) request()->integer('page', 1));
            $perPage = 50;
            $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => count($rows),
                'last_page' => max(1, (int) ceil(count($rows) / $perPage)),
            ];
        } else {
            $slice = $rows;
            $pagination = null;
        }

        return new ReportResult(
            columns: [
                self::col('document_type', 'Type'),
                self::col('document_number', 'Document'),
                self::col('project', 'Project'),
                self::col('current_stage', 'Current stage'),
                self::col('status', 'Status'),
                self::col('submitted_at', 'Submitted', 'datetime'),
                self::col('stage_entered_at', 'Stage entered', 'datetime'),
                self::col('days_pending', 'Days pending', 'number'),
                self::col('total_age', 'Total age', 'number'),
                self::col('amount', 'Amount', 'money'),
            ],
            rows: $slice,
            cards: [['key' => 'pending', 'label' => 'Pending approvals', 'value' => count($rows), 'type' => 'number']],
            notes: [
                'Stage entered uses the latest approval action timestamp when present; otherwise submitted_at.',
                'Amount is hidden without reports.view_financial.',
            ],
            pagination: $pagination,
        );
    }
}
