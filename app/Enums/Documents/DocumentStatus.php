<?php

namespace App\Enums\Documents;

use App\Enums\Concerns\HasOptions;

/**
 * draft → active (published) ⇄ archived. New versions can be added while draft or active; an
 * archived document stays readable with its full history.
 */
enum DocumentStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isEditable(): bool
    {
        return $this !== self::Archived;
    }

    public function acceptsVersions(): bool
    {
        return $this !== self::Archived;
    }
}
