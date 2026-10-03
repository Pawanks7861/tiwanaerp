<?php

namespace App\Models\Masters;

use App\Models\Concerns\HasAttachments;
use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentRepair;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\RfqVendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'code', 'name', 'contact_person', 'mobile', 'email', 'gstin', 'pan', 'state_code', 'address',
    'city', 'pincode', 'payment_terms', 'bank_name', 'bank_account_no', 'bank_ifsc', 'is_active',
])]
class Vendor extends MasterModel
{
    use HasAttachments;

    protected array $searchable = ['name', 'code', 'gstin', 'mobile', 'contact_person'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function isInUse(): bool
    {
        return RfqVendor::query()->where('vendor_id', $this->id)->exists()
            || PurchaseOrder::query()->withTrashed()->where('vendor_id', $this->id)->exists()
            || Equipment::query()->withTrashed()->where('owner_vendor_id', $this->id)->exists()
            || EquipmentRepair::query()->where('vendor_id', $this->id)->exists();
    }
}
