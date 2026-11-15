<?php

namespace App\Models\Boq;

use App\Enums\Boq\QuantitySource;
use App\Models\Concerns\HasAttachments;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * BOQ line. Derived amounts (cost_rate, cost_amount, selling_rate, client_amount) are always
 * computed by BoqCalculator on the server; line_uid is stable across revisions.
 */
#[Fillable([
    'boq_section_id', 'line_uid', 'item_code', 'name', 'description', 'hsn_sac', 'unit_id', 'quantity',
    'quantity_source', 'takeoff_reference', 'bbs_applicable',
    'material_rate', 'labour_rate', 'equipment_rate', 'subcontract_rate', 'cost_rate', 'cost_amount',
    'margin_percent', 'selling_rate', 'client_rate', 'client_amount', 'gst_rate', 'rate_analysis_id',
    'origin_variation_item_id', 'is_variation_originated', 'sort_order', 'simple_materials_initialized',
    'source_type', 'source_import_id', 'source_sheet', 'source_row',
])]
class BoqItem extends Model
{
    use HasAttachments;

    /** Internal cost fields, hidden from users without boq.view_costs. */
    public const COST_FIELDS = [
        'material_rate', 'labour_rate', 'equipment_rate', 'subcontract_rate', 'cost_rate', 'cost_amount',
        'margin_percent', 'selling_rate',
    ];

    /** Fields writable during Advanced BOQ commercial review (after technical approval). */
    public const COMMERCIAL_EDIT_FIELDS = [
        'client_rate', 'client_amount', 'margin_percent', 'selling_rate', 'updated_at',
    ];

    protected static function booted(): void
    {
        $guard = function (self $item) {
            $boq = $item->relationLoaded('boq') && $item->boq?->id === $item->boq_id
                ? $item->boq
                : Boq::query()->withoutGlobalScopes()->find($item->boq_id);
            if ($boq === null) {
                return;
            }

            if ($boq->isEditable() && ! $boq->isTechnicallyApproved()) {
                return;
            }

            if ($boq->allowsCommercialRateEdits()) {
                $dirty = array_keys($item->getDirty());
                if ($dirty === [] || array_diff($dirty, self::COMMERCIAL_EDIT_FIELDS) === []) {
                    return;
                }
            }

            $boq->assertEditable();
        };

        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_source' => QuantitySource::class,
            'bbs_applicable' => 'boolean',
            'is_variation_originated' => 'boolean',
            'simple_materials_initialized' => 'boolean',
            'material_rate' => 'decimal:4',
            'labour_rate' => 'decimal:4',
            'equipment_rate' => 'decimal:4',
            'subcontract_rate' => 'decimal:4',
            'cost_rate' => 'decimal:4',
            'cost_amount' => 'decimal:2',
            'margin_percent' => 'decimal:4',
            'selling_rate' => 'decimal:4',
            'client_rate' => 'decimal:4',
            'client_amount' => 'decimal:2',
            'gst_rate' => 'decimal:4',
            'sort_order' => 'integer',
            'source_import_id' => 'integer',
            'source_row' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Boq, $this>
     */
    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class);
    }

    /**
     * @return BelongsTo<BoqSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(BoqSection::class, 'boq_section_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<RateAnalysis, $this>
     */
    public function rateAnalysis(): BelongsTo
    {
        return $this->belongsTo(RateAnalysis::class);
    }

    public function measurementSheets(): HasMany
    {
        return $this->hasMany(BoqMeasurementSheet::class);
    }

    public function costComponents(): HasMany
    {
        return $this->hasMany(BoqCostComponent::class)->orderBy('sequence')->orderBy('id');
    }

    public function materialNorms(): HasMany
    {
        return $this->hasMany(BoqMaterialNorm::class)->orderBy('sequence')->orderBy('id');
    }

    public function simpleMaterialCosts(): HasMany
    {
        return $this->hasMany(BoqItemMaterialCost::class)->orderBy('sequence')->orderBy('id');
    }

    public function bbs(): HasOne
    {
        return $this->hasOne(BoqBbs::class);
    }

    public function rateComparisonQuotes(): HasMany
    {
        return $this->hasMany(BoqRateComparisonQuote::class)->orderBy('sequence')->orderBy('id');
    }

    public function planningMaps(): HasMany
    {
        return $this->hasMany(BoqPlanningMap::class)->orderBy('sequence')->orderBy('id');
    }

    /**
     * Attachments store under company/{id}/…; BOQ items inherit company from the parent BOQ.
     */
    protected function companyId(): Attribute
    {
        return Attribute::get(function (): ?int {
            if ($this->relationLoaded('boq') && $this->boq) {
                return (int) $this->boq->company_id;
            }

            $companyId = Boq::query()->withoutGlobalScopes()->whereKey($this->boq_id)->value('company_id');

            return $companyId !== null ? (int) $companyId : null;
        });
    }
}
