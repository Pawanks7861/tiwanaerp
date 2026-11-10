<?php

namespace App\Models\Gate;

use App\Enums\Gate\GatePassReturnCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatePassReturnItem extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'condition' => GatePassReturnCondition::class,
        ];
    }

    public function gatePassReturn(): BelongsTo
    {
        return $this->belongsTo(GatePassReturn::class);
    }

    public function gatePassItem(): BelongsTo
    {
        return $this->belongsTo(GatePassItem::class);
    }
}
