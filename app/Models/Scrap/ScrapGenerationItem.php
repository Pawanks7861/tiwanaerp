<?php

namespace App\Models\Scrap;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Masters\ScrapType;
use App\Models\Masters\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapGenerationItem extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'weight' => 'decimal:4',
            'estimated_rate' => 'decimal:4',
            'estimated_value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ScrapGeneration, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(ScrapGeneration::class, 'scrap_generation_id');
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
