<?php

namespace App\Models\Boq;

use App\Models\Masters\Material;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoqBbsAdjustment extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'diameter_mm' => 'integer',
            'adjustment_qty_kg' => 'decimal:4',
            'approved_at' => 'datetime',
        ];
    }

    public function bbs(): BelongsTo
    {
        return $this->belongsTo(BoqBbs::class, 'bbs_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
