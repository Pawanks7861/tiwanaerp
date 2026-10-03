<?php

namespace App\Models\Finance;

use App\Enums\CostHead;
use App\Models\Boq\BoqItem;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Row of the append-only project cost ledger (architecture H.14). Signed amount: postings are
 * positive costs or credits (site returns), reversals negate the row they cancel.
 */
class ProjectCostEntry extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'project_cost_ledger';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $refuse = function () {
            throw new LogicException('Project cost ledger rows are append-only; post a reversal instead.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }

    protected function casts(): array
    {
        return [
            'cost_head' => CostHead::class,
            'entry_date' => 'date',
            'amount' => 'decimal:2',
            'is_reversal' => 'boolean',
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
     * @return BelongsTo<BoqItem, $this>
     */
    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
