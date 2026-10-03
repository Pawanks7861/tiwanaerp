<?php

namespace App\Models\Crm;

use App\Models\Concerns\HasAttachments;
use App\Models\Masters\MasterModel;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'company_name', 'contact_person', 'mobile', 'email', 'gstin', 'pan', 'state_code',
    'billing_address', 'shipping_address', 'city', 'pincode', 'is_active',
])]
class Client extends MasterModel
{
    use HasAttachments;

    protected array $searchable = ['company_name', 'code', 'gstin', 'mobile', 'contact_person'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<Quotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function isInUse(): bool
    {
        return $this->projects()->exists() || $this->quotations()->withTrashed()->exists()
            || Lead::query()->withTrashed()->where('client_id', $this->id)->exists();
    }
}
