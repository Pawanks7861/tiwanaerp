<?php

namespace App\Enums\Crm;

use App\Enums\Concerns\HasOptions;

/**
 * draft → sent → accepted / rejected / expired. Revising a sent (or rejected / expired) quotation
 * marks it revised and creates the next revision as a new draft with the same number.
 * Accepted quotations are immutable and may be converted into one project.
 */
enum QuotationStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Revised = 'revised';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isRevisable(): bool
    {
        return in_array($this, [self::Sent, self::Rejected, self::Expired], true);
    }
}
