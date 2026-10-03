<?php

namespace App\Models\SiteExecution;

use App\Models\Concerns\LockedByParent;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Subcontractor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DprLabour extends Model
{
    use LockedByParent;

    public const PARENT_MODEL = Dpr::class;

    public const PARENT_KEY = 'dpr_id';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['headcount' => 'integer', 'hours' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<LabourTrade, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(LabourTrade::class, 'labour_trade_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class)->withTrashed();
    }
}
