<?php

namespace App\Enums\Documents;

use App\Enums\Concerns\HasOptions;

enum DocumentCategory: string
{
    use HasOptions;

    case Contract = 'contract';
    case Specification = 'specification';
    case Permit = 'permit';
    case Correspondence = 'correspondence';
    case Minutes = 'minutes';
    case Report = 'report';
    case Certificate = 'certificate';
    case Manual = 'manual';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Permit => 'Permit / approval',
            self::Minutes => 'Minutes of meeting',
            self::Certificate => 'Test certificate',
            default => ucfirst($this->value),
        };
    }
}
