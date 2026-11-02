<?php

namespace App\Enums\Imports;

enum ImportMode: string
{
    case Create = 'create';
    case Update = 'update';
    case Upsert = 'upsert';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Create new records',
            self::Update => 'Update existing records',
            self::Upsert => 'Create and update',
        };
    }
}
