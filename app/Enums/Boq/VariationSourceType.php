<?php

namespace App\Enums\Boq;

use App\Enums\Concerns\HasOptions;

enum VariationSourceType: string
{
    use HasOptions;

    case DrawingRevision = 'drawing_revision';
    case ClientInstruction = 'client_instruction';
    case SiteCondition = 'site_condition';
    case DesignChange = 'design_change';
    case QuantityCorrection = 'quantity_correction';
    case CommercialChange = 'commercial_change';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DrawingRevision => 'Drawing revision',
            self::ClientInstruction => 'Client instruction',
            self::SiteCondition => 'Site condition',
            self::DesignChange => 'Design change',
            self::QuantityCorrection => 'Quantity correction',
            self::CommercialChange => 'Commercial change',
            self::Other => 'Other',
        };
    }
}
