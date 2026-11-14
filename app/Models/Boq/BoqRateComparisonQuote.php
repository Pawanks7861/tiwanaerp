<?php

namespace App\Models\Boq;

use App\Enums\Boq\RateComparisonGstMode;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoqRateComparisonQuote extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $guard = function (self $quote) {
            $boq = $quote->relationLoaded('boq') && $quote->boq?->id === $quote->boq_id
                ? $quote->boq
                : Boq::query()->withoutGlobalScopes()->find($quote->boq_id);
            $boq?->assertEditable();
        };

        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'gst_mode' => RateComparisonGstMode::class,
            'quoted_rate' => 'decimal:4',
            'gst_percent' => 'decimal:4',
            'effective_rate' => 'decimal:4',
            'internal_cost_rate_snapshot' => 'decimal:4',
            'approved_boq_rate_snapshot' => 'decimal:4',
            'variance_vs_internal' => 'decimal:4',
            'variance_vs_internal_percent' => 'decimal:4',
            'variance_vs_approved' => 'decimal:4',
            'variance_vs_approved_percent' => 'decimal:4',
            'is_lowest' => 'boolean',
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
