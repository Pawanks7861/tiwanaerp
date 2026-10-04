<?php

namespace App\Models\Documents;

use App\Enums\Discipline;
use App\Enums\Documents\DrawingStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Drawing register entry. drawing_number is entered by the user and never generated or rewritten
 * by the system; it is fixed once a revision has been approved. current_revision_id always points
 * to the single approved revision in force (DrawingService).
 */
class Drawing extends Model
{
    use Auditable, BelongsToCompany, Blameable, SoftDeletes;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'discipline' => Discipline::class,
            'status' => DrawingStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return HasMany<DrawingRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(DrawingRevision::class)->orderByDesc('id');
    }

    /**
     * @return BelongsTo<DrawingRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(DrawingRevision::class, 'current_revision_id');
    }
}
