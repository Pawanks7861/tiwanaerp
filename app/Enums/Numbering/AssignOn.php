<?php

namespace App\Enums\Numbering;

use App\Enums\Concerns\HasOptions;

enum AssignOn: string
{
    use HasOptions;

    case Create = 'create';
    case Submit = 'submit';
    case Approve = 'approve';

    public function label(): string
    {
        return 'On '.$this->value;
    }
}
