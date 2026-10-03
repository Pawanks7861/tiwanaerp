<?php

namespace App\Models\Planning;

use App\Enums\Planning\DependencyType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Directed link between two tasks of the same project. Written only by TaskDependencyService.
 */
class TaskDependency extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => DependencyType::class,
            'lag_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'predecessor_id');
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'successor_id');
    }
}
