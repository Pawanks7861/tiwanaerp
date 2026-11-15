<?php

namespace App\Services\Boq;

use App\Exceptions\ApprovalException;
use App\Models\Boq\Boq;
use App\Models\User;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Validates commercial readiness after Technical Approval for Advanced BOQ.
 */
class AdvancedBoqCommercialApprovalValidator
{
    public function __construct(
        private readonly AdvancedBoqTechnicalSnapshotService $snapshots,
    ) {}

    /**
     * @return list<string>
     */
    public function errors(Boq $boq, User $actor): array
    {
        if (! BoqCompanySettings::enabled()) {
            return [];
        }

        $errors = [];

        if (! $actor->can('boq.view_costs')) {
            $errors[] = 'Commercial approval requires permission to view BOQ costs and margin.';
        }

        if ($boq->technical_approved_at === null || $boq->technical_snapshot_hash === null) {
            $errors[] = 'Technical approval must be completed before commercial approval.';
        } elseif (! $this->snapshots->matchesFrozen($boq)) {
            $errors[] = 'Technical costing changed after technical approval. Return to technical review before commercial approval.';
        }

        $items = $boq->items()->orderBy('id')->get(['id', 'item_code', 'name', 'client_rate', 'quantity']);
        foreach ($items as $item) {
            $label = $item->item_code ? "{$item->item_code} — {$item->name}" : $item->name;
            if ($item->client_rate === null || $item->client_rate === '') {
                $errors[] = "{$label}: client rate is required for commercial approval.";

                continue;
            }
            if (Decimal::of((string) $item->client_rate)->isNegative()) {
                $errors[] = "{$label}: client rate cannot be negative.";
            }
        }

        if (Decimal::of((string) ($boq->total_client_amount ?? '0'))->isNegative()) {
            $errors[] = 'Commercial BOQ value cannot be negative.';
        }

        return array_values(array_unique($errors));
    }

    public function assertReady(Boq $boq, User $actor): void
    {
        $errors = $this->errors($boq, $actor);
        if ($errors === []) {
            return;
        }

        // Approval engine path prefers ApprovalException; form/API can use ValidationException.
        throw ApprovalException::because($errors[0]);
    }

    public function assertReadyForHttp(Boq $boq, User $actor): void
    {
        $errors = $this->errors($boq, $actor);
        if ($errors === []) {
            return;
        }

        throw ValidationException::withMessages([
            'boq' => $errors[0],
            'commercial_approval' => $errors,
        ]);
    }
}
