<?php

namespace App\Models\Core;

use App\Enums\Numbering\AssignOn;
use App\Enums\Numbering\ResetFrequency;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['document_type', 'pattern', 'reset_frequency', 'assign_on'])]
class DocumentNumberFormat extends Model
{
    use Auditable, BelongsToCompany;

    protected function casts(): array
    {
        return [
            'reset_frequency' => ResetFrequency::class,
            'assign_on' => AssignOn::class,
        ];
    }
}
