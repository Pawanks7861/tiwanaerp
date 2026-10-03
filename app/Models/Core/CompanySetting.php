<?php

namespace App\Models\Core;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class CompanySetting extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
