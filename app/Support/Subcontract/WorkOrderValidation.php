<?php

namespace App\Support\Subcontract;

use App\Enums\Subcontract\WorkOrderTemplateProfile;
use App\Models\Masters\Subcontractor;
use App\Rules\ExistsInCompany;
use Illuminate\Validation\Rule;

/**
 * Shared create/update validation for web + API Work Order controllers.
 */
final class WorkOrderValidation
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $api = false): array
    {
        return [
            'subcontractor_id' => array_values(array_filter([
                'required', 'integer',
                $api ? new ExistsInCompany(Subcontractor::class) : null,
            ])),
            'wo_date' => ['required', 'date'],
            'subject' => ['nullable', 'string', 'max:255'],
            'template_profile' => ['nullable', 'string', Rule::enum(WorkOrderTemplateProfile::class)],
            'pdf_hide_quantities' => ['sometimes', 'boolean'],
            'document_terms_template_id' => ['nullable', 'integer'],
            'scope' => ['nullable', 'string', 'max:10000'],
            'contractor_scope' => ['nullable', 'string', 'max:10000'],
            'company_scope' => ['nullable', 'string', 'max:10000'],
            'payment_terms' => ['nullable', 'string', 'max:10000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'retention_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'advance_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'tax_rate_id' => ['nullable', 'integer'],
            'tds_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'safety_terms' => ['nullable', 'string', 'max:10000'],
            'penalty_terms' => ['nullable', 'string', 'max:10000'],
            'delay_penalty_per_day' => ['nullable', 'string', 'max:40'],
            'maximum_penalty_percent' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.task_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.rate' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'milestones' => ['nullable', 'array', 'max:50'],
            'milestones.*.name' => ['required', 'string', 'max:200'],
            'milestones.*.due_date' => ['nullable', 'date'],
            'milestones.*.amount_percent' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'subcontractor_id' => 'subcontractor',
            'items.*.quantity' => 'quantity',
            'items.*.rate' => 'rate',
            'milestones.*.amount_percent' => 'milestone %',
        ];
    }
}
