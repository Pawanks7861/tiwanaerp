<?php

namespace App\Models\Documents;

use App\Enums\Documents\DocumentCategory;
use App\Enums\Documents\DocumentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LocksWhenNotEditable;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Controlled project document. document_number is internal (DocumentNumberService); reference_no
 * is the external reference. Files live in immutable document_versions; current_version_id points
 * to the latest one.
 */
class Document extends Model
{
    use Auditable, BelongsToCompany, Blameable, LocksWhenNotEditable, SoftDeletes;

    public const LIFECYCLE_COLUMNS = ['status', 'archived_by', 'archived_at', 'updated_by', 'updated_at'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'category' => DocumentCategory::class,
            'archived_at' => 'datetime',
        ];
    }

    public function lockedMessage(): string
    {
        return 'An archived document cannot be changed. Restore it first.';
    }

    public function lockKey(): string
    {
        return 'document';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return BelongsTo<DocumentFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'document_folder_id');
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_no');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}
