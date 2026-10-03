<?php

namespace App\Support\Masters\Definitions;

use App\Enums\Equipment\EquipmentOwnership;
use App\Enums\Equipment\EquipmentStatus;
use App\Models\Equipment\Equipment;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\Vendor;
use App\Rules\ExistsInCompany;
use App\Support\Masters\MasterDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Equipment register. Hired equipment names its owner (a vendor). Status here only toggles
 * available ↔ disposed: assigned / under repair belong to the assignment and repair screens.
 */
class EquipmentDefinition extends MasterDefinition
{
    public function slug(): string
    {
        return 'equipment';
    }

    public function model(): string
    {
        return Equipment::class;
    }

    public function title(): string
    {
        return 'Equipment Register';
    }

    public function singular(): string
    {
        return 'Equipment';
    }

    public function numberType(): ?string
    {
        return 'equipment';
    }

    public function hasAttachments(): bool
    {
        return true;
    }

    public function with(): array
    {
        return ['equipmentType:id,name', 'ownerVendor:id,name'];
    }

    public function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 30, 'uppercase' => true, 'section' => 'General'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 150, 'section' => 'General'],
            ['name' => 'equipment_type_id', 'label' => 'Type', 'type' => 'select', 'options' => 'types', 'required' => true, 'section' => 'General'],
            ['name' => 'registration_no', 'label' => 'Registration / serial no.', 'type' => 'text', 'maxlength' => 50, 'uppercase' => true, 'section' => 'General'],
            ['name' => 'ownership', 'label' => 'Ownership', 'type' => 'select', 'options' => 'ownerships', 'required' => true, 'default' => 'owned', 'section' => 'Ownership'],
            ['name' => 'owner_vendor_id', 'label' => 'Owner (vendor)', 'type' => 'select', 'options' => 'vendors', 'showWhen' => ['ownership' => 'hired'], 'section' => 'Ownership'],
            ['name' => 'purchase_date', 'label' => 'Purchase date', 'type' => 'date', 'showWhen' => ['ownership' => 'owned'], 'section' => 'Ownership'],
            ['name' => 'purchase_value', 'label' => 'Purchase value', 'type' => 'money', 'showWhen' => ['ownership' => 'owned'], 'section' => 'Ownership'],
            ['name' => 'hourly_rate', 'label' => 'Hourly rate', 'type' => 'rate', 'section' => 'Charge-out rates', 'help' => 'Default rate for project assignments.'],
            ['name' => 'daily_rate', 'label' => 'Daily rate', 'type' => 'rate', 'section' => 'Charge-out rates'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => 'statuses', 'default' => 'available', 'section' => 'Status', 'help' => 'Assigned / under repair are set by assignments and repairs.'],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'span' => 'full', 'section' => 'Status'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => $this->codeRules('equipment', $record),
            'name' => ['required', 'string', 'max:150'],
            'equipment_type_id' => ['required', new ExistsInCompany(EquipmentType::class)],
            'registration_no' => ['nullable', 'string', 'max:50'],
            'ownership' => ['required', Rule::enum(EquipmentOwnership::class)],
            'owner_vendor_id' => ['nullable', 'required_if:ownership,hired', new ExistsInCompany(Vendor::class)],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'purchase_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,4'],
            'daily_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,4'],
            'status' => ['nullable', Rule::enum(EquipmentStatus::class)],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    public function prepare(array $data, ?Model $record): array
    {
        if (($data['ownership'] ?? null) === EquipmentOwnership::Hired->value) {
            $data['purchase_date'] = null;
            $data['purchase_value'] = null;
        } else {
            $data['owner_vendor_id'] = null;
        }
        $data['hourly_rate'] = blank($data['hourly_rate'] ?? null) ? '0' : $data['hourly_rate'];
        $data['daily_rate'] = blank($data['daily_rate'] ?? null) ? '0' : $data['daily_rate'];
        $data['status'] = $this->status($data['status'] ?? null, $record);

        return $data;
    }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Code', 'type' => 'code'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'type_name', 'label' => 'Type'],
            ['key' => 'ownership_label', 'label' => 'Ownership', 'mobile' => false],
            ['key' => 'status_label', 'label' => 'Availability'],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }

    public function row(Model $record): array
    {
        /** @var Equipment $record */
        $row = parent::row($record);
        $row['purchase_date'] = $record->purchase_date?->toDateString();

        return $row + [
            'type_name' => $record->equipmentType?->name,
            'ownership_label' => $record->ownership?->label().($record->ownerVendor ? " · {$record->ownerVendor->name}" : ''),
            'status_label' => $record->status?->label(),
        ];
    }

    public function options(Request $request): array
    {
        return [
            'types' => $this->toOptions(EquipmentType::query()->active()->orderBy('name')->get(['id', 'name'])),
            'vendors' => $this->toOptions(Vendor::query()->active()->orderBy('name')->get(['id', 'name'])),
            'ownerships' => EquipmentOwnership::options(),
            'statuses' => EquipmentStatus::options(),
        ];
    }

    /**
     * New equipment is available. Afterwards only available ↔ disposed may be chosen here, and
     * disposal only while the equipment is neither assigned nor under repair.
     */
    private function status(?string $requested, ?Model $record): string
    {
        $locked = $record ? Equipment::query()->whereKey($record->getKey())->lockForUpdate()->first(['id', 'status'])?->status : null;
        $current = $locked ?? EquipmentStatus::Available;
        $requested = EquipmentStatus::tryFrom((string) $requested) ?? $current;

        if ($requested === $current) {
            return $current->value;
        }
        if (in_array($current, [EquipmentStatus::Assigned, EquipmentStatus::UnderRepair], true)) {
            throw ValidationException::withMessages(['status' => "The equipment is {$current->label()}: return it or complete the repair first."]);
        }
        if (! in_array($requested, [EquipmentStatus::Available, EquipmentStatus::Disposed], true)) {
            throw ValidationException::withMessages(['status' => 'Assigned and under repair are set by assignments and repairs.']);
        }

        return $requested->value;
    }
}
