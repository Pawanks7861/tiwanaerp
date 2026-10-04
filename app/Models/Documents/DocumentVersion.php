<?php

namespace App\Models\Documents;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable file version of a document: written once, never updated or deleted. version_no is
 * assigned by the server (DocumentService), 1, 2, 3, ... per document.
 */
class DocumentVersion extends Model
{
    use Auditable, BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['disk', 'file_path'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Document versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Document versions are never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
