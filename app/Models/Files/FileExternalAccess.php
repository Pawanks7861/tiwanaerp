<?php

namespace App\Models\Files;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Explicit permission for one private DWG or DXF to be fetched by an external preview service.
 * Missing rows stay off.
 */
class FileExternalAccess extends Model
{
    use BelongsToCompany;

    protected $table = 'file_external_access';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'allow_external' => 'boolean',
        ];
    }
}
