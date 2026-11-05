<?php

namespace App\Models\Boq;

use App\Enums\Boq\MeasurementFormulaType;
use App\Models\Documents\DrawingRevision;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoqMeasurementLine extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'formula_type' => MeasurementFormulaType::class,
            'nos' => 'decimal:4',
            'length' => 'decimal:4',
            'width' => 'decimal:4',
            'height_depth' => 'decimal:4',
            'diameter' => 'decimal:4',
            'radius' => 'decimal:4',
            'manual_quantity' => 'decimal:4',
            'addition_quantity' => 'decimal:4',
            'deduction_quantity' => 'decimal:4',
            'calculated_base' => 'decimal:4',
            'calculated_quantity' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(BoqMeasurementSheet::class, 'boq_measurement_sheet_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function drawingRevision(): BelongsTo
    {
        return $this->belongsTo(DrawingRevision::class);
    }
}
