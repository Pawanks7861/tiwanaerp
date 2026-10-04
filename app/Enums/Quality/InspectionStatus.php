<?php

namespace App\Enums\Quality;

use App\Enums\Concerns\HasOptions;

/**
 * requested → scheduled → completed. Checkpoint results are recorded while scheduled; the overall
 * result exists only once completed, and a completed inspection is locked.
 */
enum InspectionStatus: string
{
    use HasOptions;

    case Requested = 'requested';
    case Scheduled = 'scheduled';
    case Completed = 'completed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isEditable(): bool
    {
        return $this !== self::Completed;
    }
}
