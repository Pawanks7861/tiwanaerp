<?php

namespace App\Models\Equipment;

use App\Enums\Equipment\EquipmentOwnership;
use App\Enums\Equipment\EquipmentStatus;
use App\Models\Concerns\HasAttachments;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\MasterModel;
use App\Models\Masters\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Equipment register (architecture H.13). status is driven by assignments and repairs; the
 * register itself only toggles available ↔ disposed.
 */
#[Fillable([
    'code', 'name', 'equipment_type_id', 'ownership', 'owner_vendor_id', 'registration_no', 'purchase_date',
    'purchase_value', 'hourly_rate', 'daily_rate', 'status', 'is_active', 'remarks',
])]
class Equipment extends MasterModel
{
    use HasAttachments;

    protected $table = 'equipment';

    protected array $searchable = ['name', 'code', 'registration_no'];

    protected function casts(): array
    {
        return [
            'ownership' => EquipmentOwnership::class,
            'status' => EquipmentStatus::class,
            'purchase_date' => 'date',
            'purchase_value' => 'decimal:2',
            'hourly_rate' => 'decimal:4',
            'daily_rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<EquipmentType, $this>
     */
    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function ownerVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'owner_vendor_id')->withTrashed();
    }

    /**
     * @return HasMany<EquipmentAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EquipmentAssignment::class);
    }

    /**
     * @return HasMany<EquipmentRepair, $this>
     */
    public function repairs(): HasMany
    {
        return $this->hasMany(EquipmentRepair::class);
    }

    public function isInUse(): bool
    {
        return EquipmentAssignment::query()->where('equipment_id', $this->id)->exists()
            || EquipmentFuelLog::query()->where('equipment_id', $this->id)->exists()
            || EquipmentRepair::query()->where('equipment_id', $this->id)->exists();
    }
}
