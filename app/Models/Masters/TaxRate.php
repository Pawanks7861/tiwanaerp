<?php

namespace App\Models\Masters;

use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Procurement\VendorQuotationItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'rate', 'cgst_rate', 'sgst_rate', 'igst_rate', 'cess_rate', 'is_active'])]
class TaxRate extends MasterModel
{
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'cgst_rate' => 'decimal:4',
            'sgst_rate' => 'decimal:4',
            'igst_rate' => 'decimal:4',
            'cess_rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function isInUse(): bool
    {
        return Material::query()->where('tax_rate_id', $this->id)->exists()
            || VendorQuotationItem::query()->where('tax_rate_id', $this->id)->exists()
            || PurchaseOrderItem::query()->where('tax_rate_id', $this->id)->exists();
    }
}
