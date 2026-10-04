<?php

namespace App\Models\Documents;

use App\Enums\Documents\DrawingRevisionStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * One uploaded revision of a drawing. The file (path, name, MIME, size, checksum) never changes
 * after upload; a change is a new revision with a new, never reused code. Approved revisions only
 * ever move to superseded, superseded revisions never change, and rows are never hard deleted
 * (an unsubmitted draft may be soft deleted; its code stays taken).
 */
class DrawingRevision extends Model
{
    use Auditable, BelongsToCompany, Blameable, SoftDeletes;

    public const FILE_COLUMNS = ['drawing_id', 'revision_code', 'disk', 'file_path', 'file_name', 'mime', 'extension', 'size_bytes', 'checksum'];

    protected $guarded = ['*'];

    protected $hidden = ['disk', 'file_path'];

    protected static function booted(): void
    {
        static::updating(function (self $revision) {
            $dirty = array_keys($revision->getDirty());
            if (array_intersect($dirty, self::FILE_COLUMNS) !== []) {
                throw ValidationException::withMessages(['revision' => 'A drawing revision file cannot be replaced. Upload a new revision instead.']);
            }

            $original = $revision->getOriginal('status');
            $toSuperseded = $original === DrawingRevisionStatus::Approved
                && $revision->status === DrawingRevisionStatus::Superseded
                && array_diff($dirty, ['status', 'superseded_at', 'updated_by', 'updated_at']) === [];

            if (in_array($original, [DrawingRevisionStatus::Approved, DrawingRevisionStatus::Superseded], true) && ! $toSuperseded) {
                throw ValidationException::withMessages(['revision' => 'An approved drawing revision cannot be changed.']);
            }
        });

        static::deleting(function (self $revision) {
            if ($revision->isForceDeleting()) {
                throw new LogicException('Drawing revisions are never hard deleted.');
            }
            if ($revision->getOriginal('status') !== DrawingRevisionStatus::Draft) {
                throw ValidationException::withMessages(['revision' => 'Only a draft revision that was never submitted can be withdrawn.']);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DrawingRevisionStatus::class,
            'size_bytes' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'decided_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Drawing, $this>
     */
    public function drawing(): BelongsTo
    {
        return $this->belongsTo(Drawing::class)->withTrashed();
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_revision_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
