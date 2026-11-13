<?php

namespace App\Models\Scrap;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Masters\ScrapType;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapAdjustmentItem extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<ScrapAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(ScrapAdjustment::class, 'scrap_adjustment_id');
    }

    /**
     * @return BelongsTo<ScrapType, $this>
     */
    public function scrapType(): BelongsTo
    {
        return $this->belongsTo(ScrapType::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
