<?php

namespace App\Http\Controllers\Boq;

use App\Enums\Boq\BoqStatus;
use App\Enums\Documents\DrawingRevisionStatus;
use App\Http\Resources\AttachmentResource;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\RateAnalysis;
use App\Models\Documents\DrawingRevision;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Boq\SimpleBoqMaterialCostService;
use App\Support\Boq\AdvancedBoqApprovalStage;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Math\Decimal;

/**
 * Serialises BOQ data for Inertia/JSON. Cost fields are removed on the server for users without
 * boq.view_costs, so they never reach the browser.
 */
final class BoqPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function header(Boq $boq, bool $withCosts): array
    {
        $boq->loadMissing(['parent:id,version,title', 'creator:id,name']);

        $stage = BoqCompanySettings::enabled()
            ? AdvancedBoqApprovalStage::for($boq)
            : null;

        $data = [
            ...$boq->only(['id', 'boq_number', 'version', 'title', 'revision_reason', 'is_current', 'parent_boq_id', 'total_client_amount']),
            'status' => $boq->status->value,
            'status_label' => $boq->status->label(),
            'approval_stage' => $stage?->value,
            'approval_stage_label' => $stage?->label(),
            'technical_approved_at' => $boq->technical_approved_at?->toIso8601String(),
            'technical_approved_by' => $boq->relationLoaded('technicalApprover') ? $boq->technicalApprover?->name : null,
            'approved_at' => $boq->approved_at?->toIso8601String(),
            'approved_by' => $boq->relationLoaded('approver') ? $boq->approver?->name : null,
            'created_at' => $boq->created_at?->toIso8601String(),
            'updated_at' => $boq->updated_at?->toIso8601String(),
            'created_by_name' => $boq->creator?->name,
            'cloned_from' => $boq->parent_boq_id
                ? [
                    'id' => $boq->parent?->id,
                    'version' => $boq->parent?->version,
                    'title' => $boq->parent?->title,
                    'label' => $boq->parent
                        ? "Revision {$boq->version} cloned from Revision {$boq->parent->version}"
                        : "Revision {$boq->version}",
                ]
                : null,
        ];

        if ($withCosts) {
            $data['total_cost_amount'] = $boq->total_cost_amount;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(BoqItem $item, bool $withCosts): array
    {
        $data = $item->only([
            'id', 'boq_section_id', 'line_uid', 'item_code', 'name', 'description', 'hsn_sac', 'unit_id',
            'quantity', 'client_rate', 'client_amount', 'gst_rate', 'rate_analysis_id', 'sort_order',
            'source_type', 'source_sheet', 'source_row',
        ]);
        $data['source_label'] = ($item->source_type === 'import' && $item->source_sheet && $item->source_row)
            ? "Imported from {$item->source_sheet} → Row {$item->source_row}"
            : null;

        $attachments = $item->relationLoaded('attachments')
            ? $item->attachments
            : $item->attachments()->with('uploader:id,name')->get();
        $data['attachments'] = AttachmentResource::collection(
            $attachments->each(fn ($attachment) => $attachment->setRelation('attachable', $item))
        )->resolve();

        if ($withCosts) {
            $data += $item->only(BoqItem::COST_FIELDS);
            $data['simple_materials_initialized'] = (bool) $item->simple_materials_initialized;
            $materials = $item->relationLoaded('simpleMaterialCosts')
                ? $item->simpleMaterialCosts
                : $item->simpleMaterialCosts()->get();
            $materialService = app(SimpleBoqMaterialCostService::class);
            $data['simple_materials'] = $materials->map(function ($row) use ($materialService, $item) {
                $metrics = $materialService->lineMetrics([
                    'quantity' => (string) $row->quantity,
                    'rate' => (string) $row->rate,
                ], (string) $item->quantity);

                return [
                    'id' => $row->id,
                    'material_id' => $row->material_id,
                    'material_name' => $row->material_name,
                    'quantity' => (string) $row->quantity,
                    'unit' => $row->unit,
                    'rate' => (string) $row->rate,
                    'amount' => $metrics['amount'],
                    'total_quantity' => $metrics['total_quantity'],
                    'total_cost' => $metrics['total_cost'],
                    'sequence' => $row->sequence,
                ];
            })->values()->all();
            $totals = $materialService->totals($materials, (string) $item->quantity);
            $data['simple_material_cost_per_unit'] = $totals['per_unit'];
            $data['simple_material_cost_total'] = $totals['estimated_total'];
            $data['simple_material_quantity_summary'] = $totals['quantity_summary'];
            $labourRate = (string) ($item->labour_rate ?? '0');
            $data['labour_rate'] = $labourRate;
            $data['simple_labour_cost_per_unit'] = Decimal::of($labourRate)->toMoney();
            $data['simple_labour_cost_total'] = Decimal::of((string) $item->quantity)
                ->times($labourRate)
                ->toMoney();
            $data['is_pcc_work'] = $materialService->isPccWork($item);
        }

        return $data;
    }

    /**
     * UI abilities. State is checked here as well as in the policies, because Gate::before grants
     * platform super admins every ability regardless of the document state.
     *
     * @return array<string, bool>
     */
    public static function abilities(User $user, Boq $boq): array
    {
        $editable = $boq->isEditable() && ! $boq->isTechnicallyApproved();
        $revisable = $boq->status === BoqStatus::Approved && $boq->is_current;
        $commercial = $boq->allowsCommercialRateEdits()
            && $user->can('boq.view_costs')
            && $user->can('boq.manage_cost');

        return [
            'update' => $editable && $user->can('update', $boq),
            'submit' => $editable && $user->can('submit', $boq),
            'revise' => $revisable && $user->can('revise', $boq),
            'delete' => $editable && $user->can('delete', $boq),
            'import' => $editable && $user->can('import', $boq),
            'export' => $user->can('export', $boq),
            'edit_commercial' => $commercial,
        ];
    }

    /**
     * Approved analysis as offered in the BOQ editor (costs only; caller checks view_costs).
     *
     * @return array<string, mixed>
     */
    public static function analysisOption(RateAnalysis $analysis, array $snapshot): array
    {
        return [
            'id' => $analysis->id,
            'code' => $analysis->code,
            'name' => $analysis->name,
            'unit_id' => $analysis->unit_id,
            'unit_rate' => $analysis->unit_rate,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Drawing revision as shown on BOQ create/detail (approval facts come from the linked revision,
     * not a denormalised copy on the BOQ).
     *
     * @return array<string, mixed>
     */
    public static function drawingRevision(DrawingRevision $revision, Project $project, bool $canViewFiles): array
    {
        $drawing = $revision->drawing;
        $current = $drawing && $drawing->relationLoaded('currentRevision') ? $drawing->currentRevision : null;
        $newer = $current !== null
            && (int) $current->id !== (int) $revision->id
            && $current->status === DrawingRevisionStatus::Approved;

        return [
            'id' => $revision->id,
            'drawing_id' => $drawing?->id,
            'drawing_number' => $drawing?->drawing_number,
            'title' => $drawing?->title,
            'revision_code' => $revision->revision_code,
            'status' => $revision->status->value,
            'status_label' => $revision->status->label(),
            'is_current' => $drawing !== null && (int) $drawing->current_revision_id === (int) $revision->id,
            'approved_at' => $revision->decided_at?->toIso8601String(),
            'approved_by' => $revision->decider?->name,
            'newer_revision' => $newer ? [
                'id' => $current->id,
                'revision_code' => $current->revision_code,
                'approved_at' => $current->decided_at?->toIso8601String(),
                'approved_by' => $current->relationLoaded('decider') ? $current->decider?->name : null,
            ] : null,
            'file_url' => $canViewFiles && $drawing !== null
                ? route('files.show', ['source' => 'drawing_revision', 'id' => $revision->id])
                : null,
            'download_url' => $canViewFiles && $drawing !== null
                ? route('projects.drawings.revisions.download', [$project, $drawing, $revision])
                : null,
        ];
    }
}
