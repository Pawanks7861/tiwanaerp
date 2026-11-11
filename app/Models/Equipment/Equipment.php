<?php

namespace App\Models\Equipment;

use App\Enums\Equipment\EquipmentLocationType;
use App\Enums\Equipment\EquipmentOwnership;
use App\Enums\Equipment\EquipmentStatus;
use App\Enums\Equipment\EquipmentTrackingMode;
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
    'code', 'name', 'equipment_type_id', 'ownership', 'owner_vendor_id', 'source_purchase_order_id', 'source_grn_id',
    'registration_no', 'serial_no', 'make', 'model', 'capacity_rating', 'manufacture_year', 'purchase_date',
    'warranty_start', 'warranty_end', 'purchase_value', 'hourly_rate', 'daily_rate',
    'status', 'location_type', 'location_project_id', 'location_name', 'location_notes', 'is_active',
    'tracking_mode', 'opening_quantity', 'available_quantity', 'remarks',
])]
class Equipment extends MasterModel
{
    use HasAttachments;

    protected $table = 'equipment';

    protected array $searchable = ['name', 'code', 'registration_no', 'serial_no'];

    protected function casts(): array
    {
        return [
            'ownership' => EquipmentOwnership::class,
            'status' => EquipmentStatus::class,
            'location_type' => EquipmentLocationType::class,
            'location_updated_at' => 'datetime',
            'manufacture_year' => 'integer',
            'purchase_date' => 'date',
            'warranty_start' => 'date',
            'warranty_end' => 'date',
            'purchase_value' => 'decimal:2',
            'hourly_rate' => 'decimal:4',
            'daily_rate' => 'decimal:4',
            'is_active' => 'boolean',
            'tracking_mode' => EquipmentTrackingMode::class,
            'opening_quantity' => 'decimal:4',
            'available_quantity' => 'decimal:4',
        ];
    }

    /**
     * @return HasMany<EquipmentQuantityMovement, $this>
     */
    public function quantityMovements(): HasMany
    {
        return $this->hasMany(EquipmentQuantityMovement::class)->orderByDesc('moved_at')->orderByDesc('id');
    }

    public function isQuantityTracked(): bool
    {
        return $this->tracking_mode === EquipmentTrackingMode::Quantity;
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

    /**
     * @return HasMany<EquipmentLocationHistory, $this>
     */
    public function locationHistory(): HasMany
    {
        return $this->hasMany(EquipmentLocationHistory::class)->orderByDesc('changed_at')->orderByDesc('id');
    }

    public function isInUse(): bool
    {
        return EquipmentAssignment::query()->where('equipment_id', $this->id)->exists()
            || EquipmentFuelLog::query()->where('equipment_id', $this->id)->exists()
            || EquipmentRepair::query()->where('equipment_id', $this->id)->exists();
    }
}
