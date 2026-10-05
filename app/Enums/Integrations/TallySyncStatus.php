<?php

namespace App\Enums\Integrations;

use App\Enums\Concerns\HasOptions;

enum TallySyncStatus: string
{
    use HasOptions;

    case NotSynced = 'not_synced';
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
    case NeedsMapping = 'needs_mapping';
    case Conflict = 'conflict';
    case CancelPending = 'cancel_pending';
    case ReversalSynced = 'reversal_synced';

    public function label(): string
    {
        return match ($this) {
            self::NotSynced => 'Not synced',
            self::NeedsMapping => 'Needs mapping',
            self::CancelPending => 'Cancel pending',
            self::ReversalSynced => 'Reversal synced',
            default => ucfirst($this->value),
        };
    }
}
