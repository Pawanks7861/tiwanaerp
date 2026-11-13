<?php

namespace App\Models\Scrap;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Masters\ScrapType;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapBalance extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = 'updated_at';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'qty_generated' => 'decimal:4',
            'qty_dispatched' => 'decimal:4',
            'qty_adjusted_net' => 'decimal:4',
            'last_movement_at' => 'datetime',
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
