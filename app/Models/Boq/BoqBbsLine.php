<?php

namespace App\Models\Boq;

use App\Enums\Boq\BbsBarCountRounding;
use App\Enums\Boq\BbsCountMode;
use App\Enums\Boq\BbsLapMode;
use App\Enums\Boq\BbsShapeCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoqBbsLine extends Model
{
    protected $table = 'boq_bbs_lines';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'shape_code' => BbsShapeCode::class,
            'count_mode' => BbsCountMode::class,
            'lap_mode' => BbsLapMode::class,
            'bar_count_rounding' => BbsBarCountRounding::class,
            'diameter_mm' => 'integer',
            'number_of_members' => 'integer',
            'bars_per_member' => 'integer',
            'total_bars' => 'integer',
            'faces_layers_multiplier' => 'integer',
            'sequence' => 'integer',
            'include_end_bar' => 'boolean',
            'a_length' => 'decimal:4',
            'b_length' => 'decimal:4',
            'c_length' => 'decimal:4',
            'd_length' => 'decimal:4',
            'e_length' => 'decimal:4',
            'hook_length' => 'decimal:4',
            'bend_deduction' => 'decimal:4',
            'distribution_length' => 'decimal:4',
            'start_cover' => 'decimal:4',
            'end_cover' => 'decimal:4',
            'spacing' => 'decimal:4',
            'lap_diameter_factor' => 'decimal:4',
            'lap_length' => 'decimal:4',
            'lap_count_per_bar' => 'decimal:4',
            'cutting_length' => 'decimal:4',
            'total_length' => 'decimal:4',
            'unit_weight' => 'decimal:4',
            'unit_weight_override' => 'decimal:4',
            'theoretical_weight' => 'decimal:4',
            'unit_weight_override_at' => 'datetime',
        ];
    }

    public function bbs(): BelongsTo
    {
        return $this->belongsTo(BoqBbs::class, 'bbs_id');
    }

    public function unitWeightOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unit_weight_override_by');
    }
}
