<?php

namespace App\Models\Boq;

use App\Enums\Boq\BbsShapeCode;
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
            'diameter_mm' => 'integer',
            'number_of_members' => 'integer',
            'bars_per_member' => 'integer',
            'total_bars' => 'integer',
            'sequence' => 'integer',
            'a_length' => 'decimal:4',
            'b_length' => 'decimal:4',
            'c_length' => 'decimal:4',
            'd_length' => 'decimal:4',
            'e_length' => 'decimal:4',
            'hook_length' => 'decimal:4',
            'bend_deduction' => 'decimal:4',
            'cutting_length' => 'decimal:4',
            'total_length' => 'decimal:4',
            'unit_weight' => 'decimal:4',
            'theoretical_weight' => 'decimal:4',
        ];
    }

    public function bbs(): BelongsTo
    {
        return $this->belongsTo(BoqBbs::class, 'bbs_id');
    }
}
