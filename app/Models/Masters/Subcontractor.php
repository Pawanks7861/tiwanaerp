<?php

namespace App\Models\Masters;

use App\Models\Concerns\HasAttachments;
use App\Models\Labour\Labour;
use App\Models\Subcontract\WorkOrder;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'code', 'name', 'trade', 'contact_person', 'mobile', 'email', 'gstin', 'pan', 'state_code',
    'address', 'city', 'pincode', 'payment_terms', 'bank_name', 'bank_account_no', 'bank_ifsc', 'is_active',
])]
class Subcontractor extends MasterModel
{
    use HasAttachments;

    protected array $searchable = ['name', 'code', 'trade', 'gstin', 'mobile'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function isInUse(): bool
    {
        return WorkOrder::query()->withTrashed()->where('subcontractor_id', $this->id)->exists()
            || Labour::query()->withTrashed()->where('subcontractor_id', $this->id)->exists();
    }
}
