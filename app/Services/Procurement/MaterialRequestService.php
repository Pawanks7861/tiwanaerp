<?php

namespace App\Services\Procurement;

use App\Enums\Procurement\MaterialRequestStatus;
use App\Events\Procurement\MaterialRequestSubmitted;
use App\Models\Procurement\MaterialRequest;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialRequestService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly ProcurementQuantityService $quantities,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated header + items
     */
    public function create(Project $project, array $data, User $user): MaterialRequest
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $mr = new MaterialRequest($this->header($data));
            $mr->forceFill([
                'project_id' => $project->id,
                'request_number' => $this->numbers->next('material_request', $project),
                'requested_by' => $user->id,
                'status' => MaterialRequestStatus::Draft,
            ])->save();

            $this->syncItems($mr, $data['items']);

            return $mr;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MaterialRequest $mr, array $data): MaterialRequest
    {
        return DB::transaction(function () use ($mr, $data) {
            $mr = MaterialRequest::query()->whereKey($mr->id)->lockForUpdate()->firstOrFail();
            $mr->assertEditable();
            $mr->fill($this->header($data))->save();
            $this->syncItems($mr, $data['items']);

            return $mr;
        });
    }

    public function delete(MaterialRequest $mr): void
    {
        $mr->assertEditable();
        $mr->delete();
    }

    public function submit(MaterialRequest $mr, User $user): void
    {
        $mr->assertEditable();

        if (! $mr->items()->exists()) {
            throw ValidationException::withMessages(['material_request' => 'Add at least one line before submitting.']);
        }

        $this->approvals->submit($mr, $user);
    }

    /** Called by the approval hook after the status changed to submitted. */
    public function submitted(MaterialRequest $mr): void
    {
        MaterialRequestSubmitted::dispatch($mr);
    }

    /** Final approval (inside the approval engine's transaction). */
    public function markApproved(MaterialRequest $mr, ?int $approverId): void
    {
        DB::transaction(function () use ($mr, $approverId) {
            $mr = MaterialRequest::query()->whereKey($mr->id)->lockForUpdate()->firstOrFail();
            if ($mr->status->isProcurable()) {
                return;
            }

            $mr->forceFill([
                'status' => MaterialRequestStatus::Approved,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ])->save();

            $this->quantities->refreshMaterialRequests($mr->items()->pluck('id')->all());
        });
    }

    /**
     * Cancel an approved request that has nothing reserved by RFQs or purchase orders.
     */
    public function cancel(MaterialRequest $mr, string $reason): void
    {
        DB::transaction(function () use ($mr, $reason) {
            $mr = MaterialRequest::query()->whereKey($mr->id)->lockForUpdate()->firstOrFail();
            if (! $mr->status->isProcurable()) {
                throw ValidationException::withMessages(['material_request' => 'Only approved material requests can be cancelled.']);
            }

            $allocated = $this->quantities->allocated($mr->items()->pluck('id')->all());
            foreach ($allocated as $qty) {
                if ($qty->isPositive()) {
                    throw ValidationException::withMessages([
                        'material_request' => 'Lines of this request are already on an RFQ or purchase order. Cancel those documents first.',
                    ]);
                }
            }

            $mr->forceFill(['status' => MaterialRequestStatus::Cancelled, 'cancelled_reason' => $reason])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        return [
            'site_id' => $data['site_id'] ?? null,
            'request_date' => $data['request_date'],
            'required_date' => $data['required_date'] ?? null,
            'priority' => $data['priority'],
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    /**
     * Replace the lines with the submitted rows. Rows with an id update that line (it must belong
     * to this request); lines not present are removed.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncItems(MaterialRequest $mr, array $rows): void
    {
        $existing = $mr->items()->get()->keyBy('id');
        $keep = [];

        foreach (array_values($rows) as $index => $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["items.{$index}.id" => 'This line does not belong to the material request.']);
            }

            $item = $id !== null ? $existing->get($id) : new MaterialRequestItem;
            $item->setRelation('materialRequest', $mr);
            $item->fill([
                'material_id' => (int) $row['material_id'],
                'boq_item_id' => $row['boq_item_id'] ?? null,
                'task_id' => $row['task_id'] ?? null,
                'unit_id' => (int) $row['unit_id'],
                'quantity' => Decimal::of((string) $row['quantity'])->toQuantity(),
                'remarks' => $row['remarks'] ?? null,
                'sort_order' => $index + 1,
            ]);
            $item->material_request_id = $mr->id;
            $item->save();
            $keep[] = $item->id;
        }

        $existing->except($keep)->each(function (MaterialRequestItem $item) use ($mr) {
            $item->setRelation('materialRequest', $mr);
            $item->delete();
        });
    }
}
