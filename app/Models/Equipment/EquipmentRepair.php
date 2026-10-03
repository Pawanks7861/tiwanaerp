<?php

namespace App\Models\Equipment;

use App\Enums\Equipment\RepairStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Repair / maintenance record. cost is recorded, not posted: the repair bill reaches project cost
 * as a Phase 7 expense, linked back through expense_id.
 */
class EquipmentRepair extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => RepairStatus::class,
            'repair_date' => 'date',
            'completed_date' => 'date',
            'cost' => 'decimal:2',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === RepairStatus::Open;
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }
}
