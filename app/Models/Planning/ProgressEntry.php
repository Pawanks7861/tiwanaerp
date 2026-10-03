<?php

namespace App\Models\Planning;

use App\Models\Boq\BoqItem;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Row of the append-only progress ledger (architecture H.7). Signed quantity: postings are
 * positive, reversals negate the row they cancel. Written only by ProgressLedgerService.
 */
class ProgressEntry extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $refuse = function () {
            throw new LogicException('Progress entries are append-only; post a reversal instead.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'quantity' => 'decimal:4',
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
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id')->withTrashed();
    }

    /**
     * @return BelongsTo<BoqItem, $this>
     */
    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
