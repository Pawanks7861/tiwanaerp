<?php

namespace App\Models\Core;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Company library of PO / Work Order terms. Copied into document fields on select; never live-linked after copy.
 */
#[Fillable([
    'name', 'document_type', 'template_profile', 'contractor_scope', 'company_scope',
    'payment_terms', 'safety_terms', 'general_terms', 'penalty_terms', 'is_active',
])]
class DocumentTermsTemplate extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Snapshot payload copied onto a work order (and frozen again at approval).
     *
     * @return array<string, ?string>
     */
    public function snapshotPayload(): array
    {
        return [
            'template_id' => $this->id,
            'template_name' => $this->name,
            'contractor_scope' => $this->contractor_scope,
            'company_scope' => $this->company_scope,
            'payment_terms' => $this->payment_terms,
            'safety_terms' => $this->safety_terms,
            'general_terms' => $this->general_terms,
            'penalty_terms' => $this->penalty_terms,
        ];
    }
}
