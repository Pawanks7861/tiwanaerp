<?php

namespace App\Models\Projects;

use App\Enums\ProjectRole;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Blameable;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProjectUser extends Pivot
{
    use Auditable, Blameable;

    protected $table = 'project_users';

    public $incrementing = true;

    protected $fillable = ['project_id', 'user_id', 'project_role', 'is_active'];

    protected function casts(): array
    {
        return [
            'project_role' => ProjectRole::class,
            'is_active' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
