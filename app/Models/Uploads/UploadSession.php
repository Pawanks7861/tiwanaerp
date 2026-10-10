<?php

namespace App\Models\Uploads;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One resumable upload. The bytes are never stored in MySQL.
 */
class UploadSession extends Model
{
    use BelongsToCompany;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'total_size' => 'integer',
            'chunk_bytes' => 'integer',
            'total_chunks' => 'integer',
            'uploaded_chunks' => 'integer',
            'source_id' => 'integer',
            'chunk_checksums' => 'array',
            'expires_at' => 'datetime',
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
}
