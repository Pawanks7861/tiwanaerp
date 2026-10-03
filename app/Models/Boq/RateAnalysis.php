<?php

namespace App\Models\Boq;

use App\Enums\Boq\RateAnalysisStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * Rate analysis for one unit of work. project_id null = company-wide library entry.
 * All totals are produced by RateAnalysisCalculator; approved analyses are immutable.
 */
#[Fillable(['name', 'description', 'unit_id', 'output_quantity', 'overhead_percent', 'profit_percent'])]
class RateAnalysis extends Model
{
    use Auditable, BelongsToCompany, Blameable, SoftDeletes;

    /** Computed cost columns, hidden from users without boq.view_costs. */
    public const COST_FIELDS = [
        'material_cost', 'labour_cost', 'equipment_cost', 'subcontract_cost', 'other_cost',
        'overhead_amount', 'profit_amount', 'total_cost',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $ra) {
            if ($ra->getRawOriginal('status') !== RateAnalysisStatus::Draft->value) {
                $changed = array_diff(array_keys($ra->getDirty()), ['updated_by', 'updated_at']);
                if ($changed !== []) {
                    throw self::lockedException();
                }
            }
        });

        static::deleting(function (self $ra) {
            if ($ra->getRawOriginal('status') !== RateAnalysisStatus::Draft->value) {
                throw self::lockedException();
            }
        });
    }

    public static function lockedException(): ValidationException
    {
        return ValidationException::withMessages([
            'rate_analysis' => 'This rate analysis is approved and can no longer be changed.',
        ]);
    }

    public function assertEditable(): void
    {
        if ($this->status !== RateAnalysisStatus::Draft) {
            throw self::lockedException();
        }
    }

    protected function casts(): array
    {
        return [
            'status' => RateAnalysisStatus::class,
            'output_quantity' => 'decimal:4',
            'overhead_percent' => 'decimal:4',
            'profit_percent' => 'decimal:4',
            'material_cost' => 'decimal:2',
            'labour_cost' => 'decimal:2',
            'equipment_cost' => 'decimal:2',
            'subcontract_cost' => 'decimal:2',
            'other_cost' => 'decimal:2',
            'overhead_amount' => 'decimal:2',
            'profit_amount' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'unit_rate' => 'decimal:4',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return HasMany<RateAnalysisItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RateAnalysisItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
