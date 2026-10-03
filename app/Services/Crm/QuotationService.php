<?php

namespace App\Services\Crm;

use App\Enums\Crm\QuotationStatus;
use App\Models\Core\Company;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\Quotation;
use App\Models\Crm\QuotationItem;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Projects\ProjectService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Services\Tax\GstCalculator;
use App\Support\Math\Decimal;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Client quotations: draft → sent → accepted / rejected / expired; revising a sent, rejected or
 * expired quotation marks it revised and opens the next revision (same number, revision + 1) as
 * a draft copy. Totals are computed here with GstCalculator (supplier = company state, place of
 * supply chosen on the quotation). Accepted quotations are immutable.
 *
 * Conversion of an accepted quotation creates exactly one project (converted_project_id is set
 * once, under a row lock; a retry returns the same project), reusing the quotation / lead client
 * or creating one from the lead. No BOQ is generated.
 */
class QuotationService
{
    use ResolvesProjectRefs;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly GstCalculator $gst,
        private readonly LeadService $leads,
        private readonly ProjectService $projects,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Quotation
    {
        return DB::transaction(function () use ($data) {
            $quotation = new Quotation;
            $quotation->forceFill([
                'quotation_number' => $this->numbers->next('quotation'),
                'revision' => 0,
                'status' => QuotationStatus::Draft,
                ...$this->header($data),
            ])->save();
            $this->writeItems($quotation, $data['items'] ?? []);

            return $quotation;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Quotation $quotation, array $data): Quotation
    {
        return DB::transaction(function () use ($quotation, $data) {
            $locked = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $locked->forceFill($this->header($data))->save();
            $this->writeItems($locked, $data['items'] ?? []);

            return $locked;
        });
    }

    public function delete(Quotation $quotation): void
    {
        DB::transaction(function () use ($quotation) {
            $locked = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            if ($locked->revision > 0) {
                throw ValidationException::withMessages(['quotation' => 'A revision cannot be deleted; send it or keep it as a draft.']);
            }
            $locked->items()->get()->each->delete();
            $locked->delete();
        });
    }

    public function send(Quotation $quotation): void
    {
        DB::transaction(function () use ($quotation) {
            $locked = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== QuotationStatus::Draft) {
                throw ValidationException::withMessages(['quotation' => 'Only a draft can be sent.']);
            }
            if (! $locked->items()->exists() || ! Decimal::of($locked->total_amount)->isPositive()) {
                throw ValidationException::withMessages(['quotation' => 'Add at least one priced line before sending.']);
            }
            if ($locked->valid_until && $locked->valid_until->lt(today())) {
                throw ValidationException::withMessages(['valid_until' => 'The validity date has passed; extend it before sending.']);
            }

            $locked->forceFill(['status' => QuotationStatus::Sent, 'sent_at' => now()])->save();
            $this->leads->markQuoted($locked->lead_id);
            $quotation->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function accept(Quotation $quotation, User $user): void
    {
        DB::transaction(function () use ($quotation, $user) {
            $locked = $this->lockSent($quotation);
            if ($locked->valid_until && $locked->valid_until->lt(today())) {
                throw ValidationException::withMessages(['quotation' => 'The quotation validity has passed. Mark it expired and revise it.']);
            }
            $locked->forceFill(['status' => QuotationStatus::Accepted, 'decided_by' => $user->id, 'decided_at' => now()])->save();
            $this->leads->markWon($locked->lead_id, $locked->client_id);
            $quotation->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function reject(Quotation $quotation, User $user, string $reason): void
    {
        DB::transaction(function () use ($quotation, $user, $reason) {
            $locked = $this->lockSent($quotation);
            $locked->forceFill([
                'status' => QuotationStatus::Rejected,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'rejection_reason' => mb_substr($reason, 0, 500),
            ])->save();
            $quotation->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function expire(Quotation $quotation, User $user): void
    {
        DB::transaction(function () use ($quotation, $user) {
            $locked = $this->lockSent($quotation);
            $locked->forceFill(['status' => QuotationStatus::Expired, 'decided_by' => $user->id, 'decided_at' => now()])->save();
            $quotation->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Opens the next revision as a draft copy; the revised quotation is frozen as "revised". */
    public function revise(Quotation $quotation): Quotation
    {
        return DB::transaction(function () use ($quotation) {
            $locked = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->isRevisable()) {
                throw ValidationException::withMessages(['quotation' => 'Only a sent, rejected or expired quotation can be revised.']);
            }
            $revision = (int) Quotation::query()->withTrashed()->where('quotation_number', $locked->quotation_number)->max('revision') + 1;

            $copy = $locked->replicate([
                'revision', 'parent_quotation_id', 'status', 'sent_at', 'decided_by', 'decided_at', 'rejection_reason',
                'converted_project_id', 'converted_by', 'converted_at', 'created_by', 'updated_by', 'deleted_by',
            ]);
            $copy->forceFill([
                'revision' => $revision,
                'parent_quotation_id' => $locked->id,
                'status' => QuotationStatus::Draft,
                'quotation_date' => today()->toDateString(),
                'valid_until' => $locked->valid_until && $locked->valid_until->lt(today()) ? null : $locked->valid_until?->toDateString(),
            ])->save();

            foreach ($locked->items()->get() as $item) {
                $item->replicate()->forceFill(['quotation_id' => $copy->id])->save();
            }
            $locked->forceFill(['status' => QuotationStatus::Revised])->save();

            return $copy;
        });
    }

    /**
     * Creates the project for an accepted quotation (once). Returns the project.
     *
     * @param  array<string, mixed>  $data  code, project_manager_id, start_date, expected_end_date
     */
    public function convert(Quotation $quotation, User $user, array $data): Project
    {
        return DB::transaction(function () use ($quotation, $user, $data) {
            $locked = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($locked->converted_project_id !== null) {
                return Project::query()->withTrashed()->findOrFail($locked->converted_project_id);
            }
            if ($locked->status !== QuotationStatus::Accepted) {
                throw ValidationException::withMessages(['quotation' => 'Only an accepted quotation can be converted into a project.']);
            }

            $client = $this->clientFor($locked);
            $project = $this->projects->create([
                'code' => $data['code'] ?? null,
                'name' => $locked->project_name,
                'client_id' => $client->id,
                'project_type' => $locked->project_type,
                'description' => "From quotation {$locked->displayNumber()}: {$locked->title}",
                'address' => $locked->site_address,
                'city' => $locked->city,
                'state_code' => $locked->place_of_supply_state,
                'project_manager_id' => $data['project_manager_id'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'expected_end_date' => $data['expected_end_date'] ?? null,
                'contract_value' => $locked->taxable_amount,
            ]);

            $locked->forceFill([
                'client_id' => $client->id,
                'converted_project_id' => $project->id,
                'converted_by' => $user->id,
                'converted_at' => now(),
            ])->save();
            $locked->writeAudit('converted', null, ['project_id' => $project->id, 'project_code' => $project->code, 'client_id' => $client->id]);
            if ($locked->lead_id) {
                Lead::query()->whereKey($locked->lead_id)->whereNull('client_id')->first()?->forceFill(['client_id' => $client->id])->save();
            }
            $quotation->setRawAttributes($locked->getAttributes(), true);

            return $project;
        });
    }

    private function lockSent(Quotation $quotation): Quotation
    {
        $locked = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== QuotationStatus::Sent) {
            throw ValidationException::withMessages(['quotation' => 'Only a sent quotation can be accepted, rejected or expired.']);
        }

        return $locked;
    }

    private function clientFor(Quotation $quotation): Client
    {
        $clientId = $quotation->client_id ?? ($quotation->lead_id ? Lead::query()->withTrashed()->whereKey($quotation->lead_id)->value('client_id') : null);
        if ($clientId !== null) {
            return Client::query()->withTrashed()->findOrFail($clientId);
        }

        $lead = Lead::query()->withTrashed()->findOrFail($quotation->lead_id);
        $client = new Client;
        $client->fill([
            'code' => $this->numbers->next('client'),
            'company_name' => $lead->company_name ?: $lead->name,
            'contact_person' => $lead->name,
            'mobile' => $lead->mobile,
            'email' => $lead->email,
            'state_code' => $lead->state_code ?? $quotation->place_of_supply_state,
            'billing_address' => $quotation->site_address,
            'city' => $quotation->city,
            'is_active' => true,
        ])->save();

        return $client;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        $leadId = null;
        if (filled($data['lead_id'] ?? null)) {
            $leadId = Lead::query()->whereKey((int) $data['lead_id'])->value('id')
                ?? throw ValidationException::withMessages(['lead_id' => 'Choose a lead of this company.']);
        }
        $clientId = null;
        if (filled($data['client_id'] ?? null)) {
            $clientId = Client::query()->whereKey((int) $data['client_id'])->where('is_active', true)->value('id')
                ?? throw ValidationException::withMessages(['client_id' => 'Choose an active client of this company.']);
        }
        if ($leadId === null && $clientId === null) {
            throw ValidationException::withMessages(['lead_id' => 'Choose the lead or the client this quotation is for.']);
        }
        if (filled($data['valid_until'] ?? null) && $data['valid_until'] < $data['quotation_date']) {
            throw ValidationException::withMessages(['valid_until' => 'The validity date cannot be before the quotation date.']);
        }

        $supplier = Company::query()->whereKey(app(CurrentCompany::class)->id())->value('state_code');
        if (blank($supplier)) {
            throw ValidationException::withMessages(['place_of_supply_state' => 'Set the company GST state in company settings first.']);
        }

        return [
            'lead_id' => $leadId,
            'client_id' => $clientId,
            'quotation_date' => $data['quotation_date'],
            'valid_until' => $data['valid_until'] ?? null,
            'title' => trim((string) $data['title']),
            'project_name' => trim((string) $data['project_name']),
            'project_type' => $data['project_type'] ?? null,
            'site_address' => $data['site_address'] ?? null,
            'city' => $data['city'] ?? null,
            'place_of_supply_state' => $data['place_of_supply_state'],
            'tax_type' => $this->gst->taxType($supplier, $data['place_of_supply_state'] ?? null),
            'terms' => $data['terms'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows  description, hsn_sac, unit_id, quantity, rate, discount_percent, tax_rate_id
     */
    private function writeItems(Quotation $quotation, array $rows): void
    {
        QuotationItem::query()->where('quotation_id', $quotation->id)->get()->each->delete();

        $lines = [];
        foreach (array_values($rows) as $index => $row) {
            $key = "items.{$index}";
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                throw ValidationException::withMessages(["{$key}.description" => 'Describe the line.']);
            }
            $unitId = null;
            if (filled($row['unit_id'] ?? null)) {
                $unitId = Unit::query()->whereKey((int) $row['unit_id'])->where('is_active', true)->value('id')
                    ?? throw ValidationException::withMessages(["{$key}.unit_id" => 'Choose an active unit.']);
            }
            $qty = $this->quantity($row['quantity'] ?? null, "{$key}.quantity");
            $rate = $this->amount($row['rate'] ?? null, "{$key}.rate", Decimal::RATE_SCALE, required: true);
            $discount = $this->percent($row['discount_percent'] ?? null, "{$key}.discount_percent");
            $tax = null;
            if (filled($row['tax_rate_id'] ?? null)) {
                $tax = TaxRate::query()->whereKey((int) $row['tax_rate_id'])->where('is_active', true)->first()
                    ?? throw ValidationException::withMessages(["{$key}.tax_rate_id" => 'Choose an active GST rate.']);
            }
            $line = $this->gst->line($qty->toQuantity(), $rate->toRate(), $discount->toString(), $tax, $quotation->tax_type);
            $lines[] = $line;

            (new QuotationItem)->forceFill([
                'quotation_id' => $quotation->id,
                'description' => mb_substr($description, 0, 500),
                'hsn_sac' => $row['hsn_sac'] ?? null,
                'unit_id' => $unitId,
                'quantity' => $qty->toQuantity(),
                'rate' => $rate->toRate(),
                'discount_percent' => $discount->round(Decimal::PERCENT_SCALE)->toString(),
                'tax_rate_id' => $tax?->id,
                'sort_order' => $index,
                ...$line,
            ])->save();
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one line.']);
        }

        $sum = fn (string $key) => Decimal::sum(array_column($lines, $key));
        $taxable = $sum('taxable_amount');
        $quotation->forceFill([
            'subtotal' => $sum('base_amount')->toMoney(),
            'discount_amount' => $sum('discount_amount')->toMoney(),
            'taxable_amount' => $taxable->toMoney(),
            'cgst_amount' => $sum('cgst_amount')->toMoney(),
            'sgst_amount' => $sum('sgst_amount')->toMoney(),
            'igst_amount' => $sum('igst_amount')->toMoney(),
            'total_amount' => $taxable->plus($sum('cgst_amount'))->plus($sum('sgst_amount'))->plus($sum('igst_amount'))->toMoney(),
        ])->save();
    }
}
