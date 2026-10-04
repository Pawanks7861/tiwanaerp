<?php

namespace App\Models\Reports;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A large report export generated on the queue. The file lives on the private disk and is only
 * streamed to the user who requested it, after the report itself is authorized again.
 */
class ReportExport extends Model
{
    use BelongsToCompany;

    public const QUEUED = 'queued';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
