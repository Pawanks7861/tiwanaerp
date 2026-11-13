<?php

namespace App\Support\Pdf;

use App\Enums\IndianState;
use App\Enums\Subcontract\WorkOrderTemplateProfile;
use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Models\Subcontract\WorkOrder;
use App\Support\Format\IndianNumber;
use App\Support\Math\Decimal;

/**
 * Presentation DTO for Work Order PDFs. Uses stored WO totals/terms only — no recalculation.
 */
final class WorkOrderPdfDataBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(WorkOrder $order, Project $project, Company $company): array
    {
        $order->loadMissing([
            'subcontractor',
            'approver:id,name',
            'items.unit:id,symbol',
            'milestones',
        ]);

        $settings = DocumentPdfSettings::for($company);
        $snapshot = is_array($order->terms_snapshot) ? $order->terms_snapshot : null;
        $hideQty = (bool) $order->pdf_hide_quantities;
        $profile = WorkOrderTemplateProfile::tryFrom((string) ($order->template_profile ?? ''))
            ?? WorkOrderTemplateProfile::General;
        $sub = $order->subcontractor;

        $items = $order->items->values()->map(function ($item, int $index) use ($hideQty) {
            return [
                'sr' => $index + 1,
                'description' => (string) $item->description,
                'unit' => $item->unit?->symbol,
                'quantity' => $hideQty ? null : (string) $item->quantity,
                'rate' => (string) $item->rate,
                'amount' => $hideQty ? null : (string) $item->amount,
            ];
        })->all();

        $contractorLines = array_values(array_filter([
            $sub?->address,
            collect([$sub?->city, $sub?->pincode])->filter()->implode(' - '),
        ], fn ($l) => filled($l)));

        $contractorMeta = array_values(array_filter([
            $sub?->gstin ? 'GSTIN: '.$sub->gstin : null,
            $sub?->pan ? 'PAN: '.$sub->pan : null,
            $sub?->state_code
                ? 'State: '.(IndianState::tryFrom((string) $sub->state_code)?->label() ?? $sub->state_code).' ('.$sub->state_code.')'
                : null,
            $sub?->email ? 'Email: '.$sub->email : null,
            $sub?->mobile ? 'Mobile: '.$sub->mobile : null,
            $sub?->contact_person ? 'Contact: '.$sub->contact_person : null,
        ]));

        $sections = [
            'introduction' => $this->text($snapshot['introduction'] ?? $order->scope),
            'contractor_scope' => $this->text($snapshot['contractor_scope'] ?? $order->contractor_scope),
            'company_scope' => $this->text($snapshot['company_scope'] ?? $order->company_scope),
            'payment_terms' => $this->text($snapshot['payment_terms'] ?? $order->payment_terms),
            'safety_terms' => $this->text($snapshot['safety_terms'] ?? $order->safety_terms),
            'general_terms' => $this->text($snapshot['general_terms'] ?? $order->terms),
            'penalty_terms' => $this->text($snapshot['penalty_terms'] ?? $order->penalty_terms),
        ];

        $retention = Decimal::of((string) ($order->retention_percent ?? '0'));
        $retentionLine = null;
        if (! $retention->isZero()) {
            $retentionLine = 'Retention: '.$retention->round(4)->toString().'% from each bill; release as per agreed terms.';
        }

        $penaltyBits = array_values(array_filter([
            filled($order->delay_penalty_per_day) ? 'Delay penalty: '.$order->delay_penalty_per_day.' per day.' : null,
            $order->maximum_penalty_percent !== null && ! Decimal::of((string) $order->maximum_penalty_percent)->isZero()
                ? 'Maximum penalty: '.Decimal::of((string) $order->maximum_penalty_percent)->round(4)->toString().'%.'
                : null,
            $sections['penalty_terms'],
        ]));

        $timeline = array_filter([
            'Start date' => $order->start_date?->format('d-M-Y'),
            'Completion date' => $order->end_date?->format('d-M-Y'),
            'Period' => ($order->start_date && $order->end_date)
                ? ((int) $order->start_date->diffInDays($order->end_date)).' day(s)'
                : null,
        ], fn ($v) => filled($v));

        return [
            'order' => $order,
            'project' => $project,
            'company' => $company,
            'settings' => $settings,
            'profile' => $profile,
            'title' => 'WORK ORDER',
            'subject' => filled($order->subject) ? (string) $order->subject : null,
            'meta' => array_filter([
                'Work Order No.' => $order->wo_number,
                'Date' => $order->wo_date?->format('d-M-Y'),
                'Profile' => $profile->label(),
            ], fn ($v) => filled($v)),
            'contractor' => [
                'name' => $sub?->name,
                'lines' => $contractorLines,
                'meta' => $contractorMeta,
            ],
            'project_block' => array_filter([
                'Project' => $project->name,
                'Code' => $project->code,
                'Site / Location' => collect([$project->address, $project->city])->filter()->implode(', ') ?: null,
            ], fn ($v) => filled($v)),
            'items' => $items,
            'hide_quantities' => $hideQty,
            'sections' => $sections,
            'timeline' => $timeline,
            'retention' => $retentionLine,
            'penalty' => $penaltyBits === [] ? null : implode(' ', $penaltyBits),
            'advance' => ! Decimal::of((string) ($order->advance_amount ?? '0'))->isZero()
                ? IndianNumber::money($order->advance_amount)
                : null,
            'totals' => [
                'subtotal' => (string) $order->subtotal,
                'tax_percent' => (string) $order->tax_percent,
                'tax_amount' => (string) $order->tax_amount,
                'total_value' => (string) $order->total_value,
            ],
            'show_totals' => ! $hideQty,
            'words' => IndianNumber::rupeesInWords((string) $order->total_value),
            'money' => fn ($v) => IndianNumber::money($v),
            'qty' => fn ($v) => IndianNumber::quantity($v),
            'rate' => fn ($v) => IndianNumber::rate($v),
        ];
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
