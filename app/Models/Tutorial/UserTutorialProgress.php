<?php

namespace App\Models\Tutorial;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTutorialProgress extends Model
{
    protected $table = 'user_tutorial_progress';

    protected $fillable = [
        'user_id',
        'tutorial_key',
        'tutorial_version',
        'current_step',
        'completed_steps',
        'started_at',
        'last_seen_at',
        'completed_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'completed_steps' => 'array',
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed' && $this->completed_at !== null;
    }

    public function isExempt(): bool
    {
        return $this->status === 'exempt';
    }

    /**
     * @return list<string>
     */
    public function completedStepKeys(): array
    {
        $steps = $this->completed_steps ?? [];

        return array_values(array_unique(array_map('strval', is_array($steps) ? $steps : [])));
    }
}
