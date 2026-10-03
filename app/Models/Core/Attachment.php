<?php

namespace App\Models\Core;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * File metadata. The stored path is random; original_name is metadata only.
 * Files live on the private disk and are streamed by AttachmentController after authorization.
 */
class Attachment extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
