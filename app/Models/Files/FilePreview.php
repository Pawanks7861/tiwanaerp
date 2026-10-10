<?php

namespace App\Models\Files;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Private generated preview for one source file. Bytes stay on the private disk.
 */
class FilePreview extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const UNSUPPORTED = 'unsupported';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'generated_at' => 'datetime',
        ];
    }
}
