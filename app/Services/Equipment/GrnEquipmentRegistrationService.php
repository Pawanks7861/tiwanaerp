<?php

namespace App\Services\Equipment;

use App\Enums\Equipment\EquipmentLocationType;
use App\Enums\Equipment\EquipmentOwnership;
use App\Enums\Equipment\EquipmentStatus;
use App\Enums\ItemType;
use App\Enums\Procurement\GrnStatus;
use App\Enums\Procurement\ProcurementCategory;
use App\Models\Equipment\Equipment;
use App\Models\Masters\Material;
use App\Models\Procurement\Grn;
use App\Models\Procurement\MaterialRequest;
use App\Models\User;
use App\Services\Numbering\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers equipment assets from an approved GRN without finance side effects.
 */
class GrnEquipmentRegistrationService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array{name:string, equipment_type_id:int, registration_no?:?string, serial_no?:?string, make?:?string, model?:?string, capacity_rating?:?string, warranty_start?:?string, warranty_end?:?string, quantity?:int}  $data
     * @return list<Equipment>
     */
    public function register(Grn $grn, array $data, User $user): array
    {
        unset($user);

        if ($grn->status !== GrnStatus::Approved) {
            throw ValidationException::withMessages(['grn' => 'Only an approved GRN can register equipment.']);
        }

        $registration = trim((string) ($data['registration_no'] ?? ''));
        $serial = trim((string) ($data['serial_no'] ?? ''));
        if ($registration === '' && $serial === '') {
            throw ValidationException::withMessages(['registration_no' => 'Registration number or serial number is required.']);
        }
        if (blank($data['equipment_type_id'] ?? null)) {
            throw ValidationException::withMessages(['equipment_type_id' => 'Equipment type is required.']);
        }

        $qty = max(1, (int) ($data['quantity'] ?? 1));
        if ($qty > 50) {
            throw ValidationException::withMessages(['quantity' => 'Register at most 50 equipment records per action.']);
        }
        if (! $this->grnIsEquipmentSupply($grn)) {
            throw ValidationException::withMessages(['grn' => 'This GRN is not marked as equipment supply and has no asset items.']);
        }

        return DB::transaction(function () use ($grn, $data, $qty, $registration, $serial) {
            $created = [];
            for ($i = 0; $i < $qty; $i++) {
                $suffix = $qty > 1 ? '-'.($i + 1) : '';
                $equipment = new Equipment;
                $equipment->forceFill([
                    'code' => $this->numbers->next('equipment'),
                    'name' => $data['name'],
                    'equipment_type_id' => (int) $data['equipment_type_id'],
                    'ownership' => EquipmentOwnership::Owned,
                    'owner_vendor_id' => $grn->purchaseOrder?->vendor_id,
                    'registration_no' => $registration !== '' ? $registration.$suffix : null,
                    'serial_no' => $serial !== '' ? ($qty > 1 ? $serial.$suffix : $serial) : null,
                    'make' => $data['make'] ?? null,
                    'model' => $data['model'] ?? null,
                    'capacity_rating' => $data['capacity_rating'] ?? null,
                    'purchase_date' => $grn->receipt_date?->toDateString(),
                    'warranty_start' => $data['warranty_start'] ?? null,
                    'warranty_end' => $data['warranty_end'] ?? null,
                    'source_purchase_order_id' => $grn->purchase_order_id,
                    'source_grn_id' => $grn->id,
                    'status' => EquipmentStatus::Available,
                    'location_type' => EquipmentLocationType::ProjectSite,
                    'location_project_id' => $grn->project_id,
                    'location_name' => $grn->project?->name,
                    'is_active' => true,
                    'remarks' => "Registered from GRN {$grn->grn_number}",
                ])->save();
                $created[] = $equipment;
            }

            return $created;
        });
    }

    public function grnIsEquipmentSupply(Grn $grn): bool
    {
        $materialIds = $grn->items()->pluck('material_id')->filter()->all();
        if ($materialIds !== [] && Material::query()->whereIn('id', $materialIds)->where('item_type', ItemType::Asset)->exists()) {
            return true;
        }

        if (! $grn->purchase_order_id) {
            return false;
        }

        return MaterialRequest::query()
            ->where('procurement_category', ProcurementCategory::Equipment->value)
            ->whereHas('items', function ($q) use ($grn) {
                $q->whereIn('id', function ($sub) use ($grn) {
                    $sub->select('material_request_item_id')
                        ->from('purchase_order_items')
                        ->where('purchase_order_id', $grn->purchase_order_id)
                        ->whereNotNull('material_request_item_id');
                });
            })
            ->exists();
    }
}
