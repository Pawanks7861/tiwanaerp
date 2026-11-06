<?php

namespace App\Models\Equipment;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Inventory\MaterialIssue;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Models\Masters\Warehouse;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fuel record of a machine on a project. cost is informational (fuel_added × fuel_rate): it is not
 * posted to the project cost ledger, because the fuel reaches project cost through the purchase
 * route (diesel material issue, or a Phase 7 expense / vendor bill). Posting it here too would
 * count the same fuel twice.
 */
class EquipmentFuelLog extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'log_date' => 'date',
            'opening_fuel' => 'decimal:2',
            'fuel_added' => 'decimal:2',
            'fuel_consumed' => 'decimal:2',
            'closing_fuel' => 'decimal:2',
            'fuel_rate' => 'decimal:4',
            'cost' => 'decimal:2',
            'meter_reading' => 'decimal:2',
        ];
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
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<MaterialIssue, $this>
     */
    public function materialIssue(): BelongsTo
    {
        return $this->belongsTo(MaterialIssue::class);
    }
}
