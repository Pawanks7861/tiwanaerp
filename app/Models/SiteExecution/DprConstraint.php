<?php

namespace App\Models\SiteExecution;

use App\Models\Concerns\LockedByParent;
use App\Models\Planning\ProjectTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DprConstraint extends Model
{
    use LockedByParent;

    public const PARENT_MODEL = Dpr::class;

    public const PARENT_KEY = 'dpr_id';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'raised_date' => 'date',
            'expected_resolution' => 'date',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Dpr, $this> */
    public function dpr(): BelongsTo
    {
        return $this->belongsTo(Dpr::class);
    }

    /** @return BelongsTo<ProjectTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id')->withTrashed();
    }
}
