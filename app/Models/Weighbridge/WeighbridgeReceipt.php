<?php

namespace App\Models\Weighbridge;

use App\Enums\Weighbridge\WeighbridgeSource;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WeighbridgeReceipt extends Model
{
    use Auditable, BelongsToCompany, Blameable, HasAttachments;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'source' => WeighbridgeSource::class,
            'weighed_at' => 'datetime',
            'gross_weight' => 'decimal:4',
            'tare_weight' => 'decimal:4',
            'net_weight' => 'decimal:4',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function weighable(): MorphTo
    {
        return $this->morphTo();
    }
}
