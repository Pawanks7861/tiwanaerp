<?php

namespace App\Http\Controllers\Procurement;

use App\Contracts\Approvable;
use App\Http\Resources\AttachmentResource;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Masters\Material;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use App\Models\Masters\Vendor;
use App\Models\Masters\Warehouse;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared serialisation for procurement pages. Only the fields a page needs are sent; vendor bank
 * details and internal BOQ costs never reach the browser.
 */
final class ProcurementPresenter
{
    /**
     * Pending approval request of an engine-approved document (MR, PO, GRN).
     *
     * @return array<string, mixed>|null
     */
    public static function approval(Approvable&Model $document, User $user): ?array
    {
        $pending = $document->pendingApprovalRequest();
        if ($pending === null) {
            return null;
        }

        return [
            'id' => $pending->id,
            'level' => $pending->current_level,
            'levels' => count($pending->steps ?? []),
            'step_name' => $pending->currentStep()['name'] ?? null,
            'can_act' => app(ApprovalService::class)->canAct($pending, $user),
            'can_cancel' => (int) $pending->submitted_by === (int) $user->id,
        ];
    }

    /**
     * @param  Model&object{attachments: MorphMany}  $document
     * @return list<array<string, mixed>>
     */
    public static function attachments(Model $document): array
    {
        return AttachmentResource::collection($document->attachments()->with('uploader:id,name')->get())->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    public static function vendor(?Vendor $vendor): ?array
    {
        return $vendor ? $vendor->only(['id', 'code', 'name', 'gstin', 'state_code', 'city', 'contact_person', 'mobile', 'email', 'payment_terms']) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function unitOptions(): array
    {
        return Unit::query()->active()->orderBy('symbol')->get(['id', 'name', 'symbol'])
            ->map(fn (Unit $u) => ['value' => $u->id, 'label' => $u->symbol, 'description' => $u->name])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function materialOptions(): array
    {
        return Material::query()->active()->orderBy('name')->limit(2000)->get(['id', 'code', 'name', 'unit_id', 'hsn_sac', 'tax_rate_id'])
            ->map(fn (Material $m) => [
                'value' => $m->id,
                'label' => $m->name,
                'description' => trim($m->code.($m->hsn_sac ? ' · HSN '.$m->hsn_sac : '')),
                'unit_id' => $m->unit_id,
                'tax_rate_id' => $m->tax_rate_id,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function taxRateOptions(): array
    {
        return TaxRate::query()->active()->orderBy('rate')->get(['id', 'name', 'rate', 'cgst_rate', 'sgst_rate', 'igst_rate'])
            ->map(fn (TaxRate $t) => [
                'value' => $t->id,
                'label' => $t->name,
                'rate' => $t->rate,
                'cgst_rate' => $t->cgst_rate,
                'sgst_rate' => $t->sgst_rate,
                'igst_rate' => $t->igst_rate,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function vendorOptions(): array
    {
        return Vendor::query()->active()->orderBy('name')->get(['id', 'code', 'name', 'gstin', 'state_code', 'city'])
            ->map(fn (Vendor $v) => [
                'value' => $v->id,
                'label' => $v->name,
                'description' => collect([$v->code, $v->city, $v->state_code ? 'State '.$v->state_code : 'No GST state'])->filter()->implode(' · '),
                'state_code' => $v->state_code,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function siteOptions(Project $project): array
    {
        return Site::query()->where('project_id', $project->id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Site $s) => ['value' => $s->id, 'label' => $s->name])->all();
    }

    /**
     * Warehouses of this project plus company-level (central) warehouses.
     *
     * @return list<array<string, mixed>>
     */
    public static function warehouseOptions(Project $project): array
    {
        return Warehouse::query()->active()
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $project->id))
            ->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (Warehouse $w) => ['value' => $w->id, 'label' => $w->name, 'description' => $w->code])->all();
    }

    /**
     * Lines of the project's current approved BOQ (no cost fields).
     *
     * @return list<array<string, mixed>>
     */
    public static function boqItemOptions(Project $project): array
    {
        $boqId = Boq::query()->where('project_id', $project->id)->where('is_current', true)->where('status', 'approved')->value('id');
        if ($boqId === null) {
            return [];
        }

        return BoqItem::query()->where('boq_id', $boqId)->orderBy('sort_order')->get(['id', 'item_code', 'name', 'unit_id', 'quantity'])
            ->map(fn (BoqItem $i) => [
                'value' => $i->id,
                'label' => trim(($i->item_code ? $i->item_code.' ' : '').$i->name),
                'description' => 'BOQ qty '.rtrim(rtrim((string) $i->quantity, '0'), '.'),
                'unit_id' => $i->unit_id,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function taskOptions(Project $project): array
    {
        return ProjectTask::query()->where('project_id', $project->id)->orderBy('sort_order')->orderBy('id')->get(['id', 'wbs_code', 'name'])
            ->map(fn (ProjectTask $t) => ['value' => $t->id, 'label' => trim($t->wbs_code.' '.$t->name)])->all();
    }
}
