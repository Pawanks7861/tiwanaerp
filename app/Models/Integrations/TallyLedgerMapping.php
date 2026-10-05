<?php

namespace App\Models\Integrations;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TallyLedgerMapping extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'auto_create_allowed' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
