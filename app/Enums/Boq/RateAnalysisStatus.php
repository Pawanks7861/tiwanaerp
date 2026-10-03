<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum RateAnalysisStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Approved = 'approved';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
