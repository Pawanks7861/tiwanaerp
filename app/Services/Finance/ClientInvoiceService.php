<?php

namespace App\Services\Finance;

use App\Enums\Boq\BoqStatus;
use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\Finance\PaymentPartyType;
use App\Enums\Finance\PaymentStatus;
use App\Enums\Procurement\TaxType;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Core\Company;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\ClientInvoiceItem;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentAllocation;
use App\Models\Masters\TaxRate;
use App\Models\Planning\ProgressEntry;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Services\Tax\GstCalculator;
use App\Support\Math\Decimal;
use App\Support\Permissions\CompanyPermission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Client RA bills: draft → submitted (engine: PM → Director) → certified → partially_paid / paid.
 *
 * Lines are measured against the project's current approved BOQ, keyed by line_uid:
 * executed = Σ progress ledger quantity up to period_to; previous = Σ current quantity of the
 * line on earlier certified bills; cumulative = previous + current ≤ min(executed, BOQ quantity)
 * unless the user holds billing.override_qty and justifies the line (audited). Rate = BOQ client
 * rate (snapshot). gross = Σ round(current × rate, 2); GST (bill-level rate) via GstCalculator
 * with supplier = company state and place of supply = project state; retention and TDS =
 * round(gross × %, 2); advance recovery ≤ the client's unadjusted advance;
 * net = gross + GST − retention − advance − TDS − other deductions ≥ 0.
 *
 * One open (draft / submitted / rejected) bill per project, so "previous" cannot move between
 * submission and certification. The tax invoice number is assigned at certification. Client
 * bills never touch the project cost ledger.
 */
class ClientInvoiceService
{
    use ResolvesProjectRefs;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly GstCalculator $gst,
        private readonly PayableService $payables,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data, User $user): ClientInvoice
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($lockedProject->client_id === null) {
                throw ValidationException::withMessages(['project' => 'Link a client to the project before raising RA bills.']);
            }
            $open = ClientInvoice::query()->where('project_id', $project->id)->whereIn('status', ClientInvoiceStatus::openStates())->first();
            if ($open !== null) {
                throw ValidationException::withMessages(['project' => "RA bill {$open->ra_sequence} is still open. Certify or delete it before raising the next bill."]);
            }
            $boq = $this->currentBoq($lockedProject);
            [$supplier, $type] = $this->taxPosition($lockedProject);

            $invoice = new ClientInvoice;
            $invoice->forceFill([
                'project_id' => $project->id,
                'client_id' => $lockedProject->client_id,
                'boq_id' => $boq->id,
                'ra_sequence' => (int) ClientInvoice::query()->withTrashed()->where('project_id', $project->id)->max('ra_sequence') + 1,
                'supplier_state' => $supplier,
                'place_of_supply_state' => $lockedProject->state_code,
                'tax_type' => $type,
                'status' => ClientInvoiceStatus::Draft,
                ...$this->header($data),
            ])->save();

            $this->writeLines($invoice, $boq, $data['items'] ?? [], $user);
            $this->recalculate($invoice, $data);

            return $invoice;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ClientInvoice $invoice, array $data, User $user): ClientInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $user) {
            $locked = ClientInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $project = $locked->project;
            $boq = $this->currentBoq($project);
            [$supplier, $type] = $this->taxPosition($project);

            $locked->forceFill([
                'boq_id' => $boq->id,
                'supplier_state' => $supplier,
                'place_of_supply_state' => $project->state_code,
                'tax_type' => $type,
                ...$this->header($data),
            ])->save();
            $this->writeLines($locked, $boq, $data['items'] ?? [], $user);
            $this->recalculate($locked, $data);

            return $locked;
        });
    }

    /** Only a never-submitted draft can be deleted (its RA sequence is reused). */
    public function delete(ClientInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $locked = ClientInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ClientInvoiceStatus::Draft || $locked->approvalRequests()->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Only a draft that was never submitted can be deleted; edit and resubmit it instead.']);
            }
            $locked->forceDelete();
        });
    }

    public function submit(ClientInvoice $invoice, User $user): void
    {
        DB::transaction(function () use ($invoice, $user) {
            $locked = ClientInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $locked->assertEditable();
            $project = $locked->project;
            $boq = $this->currentBoq($project);

            $rows = $locked->items()->get()->map(fn (ClientInvoiceItem $i) => [
                'boq_line_uid' => $i->boq_line_uid,
                'current_qty' => $i->current_qty,
                'override_reason' => $i->override_reason,
            ])->all();
            $this->writeLines($locked, $boq, $rows, $user, keepOverrides: true);
            $this->recalculate($locked, $locked->only(['tax_rate_id', 'retention_percent', 'tds_percent', 'advance_recovery', 'other_deductions']));
            if (Decimal::of($locked->gross_amount)->isZero()) {
                throw ValidationException::withMessages(['invoice' => 'Bill a quantity on at least one BOQ line before submitting.']);
            }

            $this->approvals->submit($locked, $user);
            $invoice->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Final approval (inside the engine's transaction): re-validates every line against the
     * executed and BOQ quantities and earlier certified bills, re-checks the advance, assigns the
     * tax invoice number and locks the bill. The final approver needs billing.certify. Idempotent.
     */
    public function certify(ClientInvoice $invoice, ?int $approverId): void
    {
        DB::transaction(function () use ($invoice, $approverId) {
            Project::query()->whereKey($invoice->project_id)->lockForUpdate()->firstOrFail();
            $locked = ClientInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->isCertified()) {
                return;
            }
            if ($locked->status !== ClientInvoiceStatus::Submitted) {
                throw ValidationException::withMessages(['invoice' => 'Only a submitted RA bill can be certified.']);
            }
            $approver = $approverId ? User::query()->find($approverId) : null;
            if (! CompanyPermission::check($approver, (int) $locked->company_id, 'billing.certify')) {
                throw ValidationException::withMessages(['approval' => 'Certifying a client RA bill needs the billing.certify permission.']);
            }

            $boqItems = $this->boqItems($this->currentBoq($locked->project));
            $items = $locked->items()->get();
            $previous = $this->certifiedQuantities($locked->project_id, $items->pluck('boq_line_uid')->all(), $locked->id);
            $executed = $this->executedQuantities($locked->project_id, $items->pluck('boq_line_uid')->all(), $locked->period_to->toDateString());
            foreach ($items as $item) {
                if (! Decimal::of($item->previous_qty)->equals($previous[$item->boq_line_uid] ?? '0')) {
                    throw ValidationException::withMessages(['invoice' => "The previously billed quantity of \"{$item->description}\" changed. Send the bill back and resubmit it."]);
                }
                $boqQty = $boqItems->get($item->boq_line_uid)?->quantity;
                if ($boqQty === null) {
                    throw ValidationException::withMessages(['invoice' => "\"{$item->description}\" is no longer on the current BOQ. Send the bill back and resubmit it."]);
                }
                if (! $item->is_override) {
                    $this->assertWithinLimits($item->description, Decimal::of($item->cumulative_qty), $executed[$item->boq_line_uid] ?? Decimal::zero(), Decimal::of($boqQty), 'invoice');
                }
            }
            $this->assertAdvance($locked, Decimal::of($locked->advance_recovery));

            $locked->forceFill([
                'status' => ClientInvoiceStatus::Certified,
                'invoice_number' => $this->numbers->next('client_invoice', $locked->project, $locked->invoice_date),
                'certified_by' => $approverId,
                'certified_at' => now(),
            ])->save();
            $invoice->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * BOQ lines with their billing position for the measurement form.
     *
     * @return list<array<string, mixed>>
     */
    public function billableLines(Project $project, string $periodTo, ?ClientInvoice $except = null): array
    {
        $boq = Boq::query()->where('project_id', $project->id)->where('is_current', true)->where('status', BoqStatus::Approved)->first();
        if ($boq === null) {
            return [];
        }
        $items = BoqItem::query()->where('boq_id', $boq->id)->with('unit:id,symbol')->orderBy('sort_order')->orderBy('id')->get();
        $uids = $items->pluck('line_uid')->all();
        $previous = $this->certifiedQuantities($project->id, $uids, $except?->id);
        $executed = $this->executedQuantities($project->id, $uids, $periodTo);

        return $items->map(function (BoqItem $i) use ($previous, $executed) {
            $prev = $previous[$i->line_uid] ?? Decimal::zero();
            $exec = $executed[$i->line_uid] ?? Decimal::zero();
            $limit = Decimal::of($i->quantity)->lessThan($exec) ? Decimal::of($i->quantity) : $exec;
            $balance = $limit->minus($prev);

            return [
                'boq_item_id' => $i->id,
                'boq_line_uid' => $i->line_uid,
                'item_code' => $i->item_code,
                'description' => $i->name ?: $i->description,
                'unit' => $i->unit?->symbol,
                'boq_qty' => $i->quantity,
                'executed_qty' => $exec->toQuantity(),
                'previous_qty' => $prev->toQuantity(),
                'balance_qty' => ($balance->isNegative() ? Decimal::zero() : $balance)->toQuantity(),
                'rate' => $i->client_rate,
            ];
        })->all();
    }

    /**
     * Client advance not yet adjusted: approved receipts from the project's client that are not
     * allocated to any bill, minus advance already recovered on submitted / certified bills.
     */
    public function advanceBalance(int $projectId, int $clientId, ?int $exceptInvoiceId = null): Decimal
    {
        $receiptIds = Payment::query()->where('project_id', $projectId)->where('party_type', PaymentPartyType::Client)
            ->where('party_id', $clientId)->where('status', PaymentStatus::Approved)->pluck('id');
        $received = Decimal::sum(Payment::query()->whereIn('id', $receiptIds)->pluck('amount')->all());
        $allocated = Decimal::sum(PaymentAllocation::query()->whereIn('payment_id', $receiptIds)->pluck('amount')->all());
        $recovered = Decimal::sum(ClientInvoice::query()->where('project_id', $projectId)->where('client_id', $clientId)
            ->whereIn('status', [ClientInvoiceStatus::Submitted, ...ClientInvoiceStatus::certifiedStates()])
            ->when($exceptInvoiceId, fn ($q) => $q->whereKeyNot($exceptInvoiceId))
            ->pluck('advance_recovery')->all());

        return $received->minus($allocated)->minus($recovered);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        if ($data['period_to'] < $data['period_from']) {
            throw ValidationException::withMessages(['period_to' => 'The period must end on or after its start.']);
        }
        if ($data['invoice_date'] < $data['period_from']) {
            throw ValidationException::withMessages(['invoice_date' => 'The bill date cannot be before the start of the period.']);
        }

        return [
            'invoice_date' => $data['invoice_date'],
            'period_from' => $data['period_from'],
            'period_to' => $data['period_to'],
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    private function currentBoq(Project $project): Boq
    {
        return Boq::query()->where('project_id', $project->id)->where('is_current', true)->where('status', BoqStatus::Approved)->first()
            ?? throw ValidationException::withMessages(['project' => 'The project has no approved BOQ to bill against.']);
    }

    /**
     * @return array{0: string, 1: TaxType}
     */
    private function taxPosition(Project $project): array
    {
        $supplier = Company::query()->whereKey($project->company_id)->value('state_code');
        if (blank($supplier)) {
            throw ValidationException::withMessages(['project' => 'Set the company GST state in company settings before billing.']);
        }
        if (blank($project->state_code)) {
            throw ValidationException::withMessages(['project' => 'Set the project state (place of supply) before billing.']);
        }

        return [$supplier, $this->gst->taxType($supplier, $project->state_code)];
    }

    /**
     * @return Collection<string, BoqItem>
     */
    private function boqItems(Boq $boq): Collection
    {
        return BoqItem::query()->where('boq_id', $boq->id)->get()->keyBy('line_uid');
    }

    /**
     * @param  list<array<string, mixed>>  $rows  boq_item_id or boq_line_uid, current_qty, override_reason
     */
    private function writeLines(ClientInvoice $invoice, Boq $boq, array $rows, User $user, bool $keepOverrides = false): void
    {
        $boqItems = $this->boqItems($boq);
        $byId = $boqItems->keyBy('id');
        $existing = ClientInvoiceItem::query()->where('client_invoice_id', $invoice->id)->get()->keyBy('boq_line_uid');
        $canOverride = CompanyPermission::check($user, (int) $invoice->company_id, 'billing.override_qty');

        $parsed = [];
        foreach (array_values($rows) as $index => $row) {
            $key = "items.{$index}";
            $boqItem = isset($row['boq_line_uid']) ? $boqItems->get((string) $row['boq_line_uid']) : $byId->get((int) ($row['boq_item_id'] ?? 0));
            if ($boqItem === null) {
                throw ValidationException::withMessages(["{$key}.boq_item_id" => 'This line is not on the current approved BOQ of the project.']);
            }
            if (isset($parsed[$boqItem->line_uid])) {
                throw ValidationException::withMessages(["{$key}.boq_item_id" => 'Each BOQ line can appear once per bill.']);
            }
            $current = $this->quantity($row['current_qty'] ?? null, "{$key}.current_qty", allowZero: true);
            if ($current->isZero()) {
                continue;
            }
            $parsed[$boqItem->line_uid] = [$boqItem, $current, trim((string) ($row['override_reason'] ?? '')), $key];
        }
        if ($parsed === []) {
            throw ValidationException::withMessages(['items' => 'Bill a quantity on at least one BOQ line.']);
        }

        $uids = array_keys($parsed);
        $previous = $this->certifiedQuantities($invoice->project_id, $uids, $invoice->id);
        $executed = $this->executedQuantities($invoice->project_id, $uids, $invoice->period_to->toDateString());

        $sort = 0;
        foreach ($parsed as $uid => [$boqItem, $current, $reason, $key]) {
            $prev = $previous[$uid] ?? Decimal::zero();
            $exec = $executed[$uid] ?? Decimal::zero();
            $cumulative = $prev->plus($current);
            $limit = Decimal::of($boqItem->quantity)->lessThan($exec) ? Decimal::of($boqItem->quantity) : $exec;
            $over = $cumulative->greaterThan($limit);
            $line = $existing->get($uid);
            $wasOverride = $line?->is_override && $keepOverrides;
            $overrideChanged = ! $line?->is_override || $line->override_reason !== $reason
                || ! Decimal::of($line->cumulative_qty)->equals($cumulative);

            if ($over && ! $wasOverride) {
                if (! $canOverride) {
                    $this->assertWithinLimits($boqItem->name ?: $boqItem->description, $cumulative, $exec, Decimal::of($boqItem->quantity), "{$key}.current_qty");
                }
                if (mb_strlen($reason) < 10) {
                    throw ValidationException::withMessages(["{$key}.override_reason" => 'Billing beyond the executed / BOQ quantity needs a justification (at least 10 characters).']);
                }
            }

            ($line ?? new ClientInvoiceItem)->forceFill([
                'client_invoice_id' => $invoice->id,
                'boq_item_id' => $boqItem->id,
                'boq_line_uid' => $uid,
                'item_code' => $boqItem->item_code,
                'description' => mb_substr((string) ($boqItem->name ?: $boqItem->description), 0, 500),
                'unit_id' => $boqItem->unit_id,
                'boq_qty' => $boqItem->quantity,
                'executed_qty' => $exec->toQuantity(),
                'previous_qty' => $prev->toQuantity(),
                'current_qty' => $current->toQuantity(),
                'cumulative_qty' => $cumulative->toQuantity(),
                'rate' => $boqItem->client_rate,
                'current_amount' => $current->times($boqItem->client_rate)->round(2)->toMoney(),
                'is_override' => $over,
                'override_reason' => $over ? ($wasOverride && $reason === '' ? $line->override_reason : $reason) : null,
                'sort_order' => $sort++,
            ])->save();

            if ($over && ! $wasOverride && $overrideChanged) {
                $invoice->writeAudit('quantity_override', null, [
                    'boq_line_uid' => $uid,
                    'cumulative_qty' => $cumulative->toQuantity(),
                    'executed_qty' => $exec->toQuantity(),
                    'boq_qty' => (string) $boqItem->quantity,
                    'reason' => $reason,
                ]);
            }
        }

        foreach ($existing->reject(fn (ClientInvoiceItem $i) => isset($parsed[$i->boq_line_uid])) as $dropped) {
            $dropped->delete();
        }
    }

    private function assertWithinLimits(string $description, Decimal $cumulative, Decimal $executed, Decimal $boqQty, string $key): void
    {
        if ($cumulative->greaterThan($executed)) {
            throw ValidationException::withMessages([$key => "\"{$description}\": cumulative billed {$cumulative->toQuantity()} exceeds the executed quantity {$executed->toQuantity()}."]);
        }
        if ($cumulative->greaterThan($boqQty)) {
            throw ValidationException::withMessages([$key => "\"{$description}\": cumulative billed {$cumulative->toQuantity()} exceeds the BOQ quantity {$boqQty->toQuantity()}."]);
        }
    }

    /**
     * @param  array<string, mixed>  $data  tax_rate_id, retention_percent, tds_percent, advance_recovery, other_deductions
     */
    private function recalculate(ClientInvoice $invoice, array $data): void
    {
        $tax = null;
        if (filled($data['tax_rate_id'] ?? null)) {
            $tax = TaxRate::query()->whereKey((int) $data['tax_rate_id'])->where('is_active', true)->first()
                ?? throw ValidationException::withMessages(['tax_rate_id' => 'Choose an active GST rate.']);
        }

        $gross = Decimal::sum(ClientInvoiceItem::query()->where('client_invoice_id', $invoice->id)->pluck('current_amount')->all());
        $gst = $this->gst->onAmount($gross->toMoney(), $tax, $invoice->tax_type);
        $retentionPercent = $this->percent($data['retention_percent'] ?? null, 'retention_percent');
        $tdsPercent = $this->percent($data['tds_percent'] ?? null, 'tds_percent');
        $retention = $gross->percentOf($retentionPercent)->round(2);
        $tds = $gross->percentOf($tdsPercent)->round(2);
        $advance = $this->amount($data['advance_recovery'] ?? null, 'advance_recovery');
        $other = $this->amount($data['other_deductions'] ?? null, 'other_deductions');
        $total = $gross->plus($gst['tax_amount']);

        $net = $total->minus($retention)->minus($advance)->minus($tds)->minus($other);
        if ($net->isNegative()) {
            throw ValidationException::withMessages(['other_deductions' => 'Deductions cannot exceed the bill amount (net payable would be '.$net->toMoney().').']);
        }

        $invoice->forceFill([
            'tax_rate_id' => $tax?->id,
            'gross_amount' => $gross->toMoney(),
            'cgst_amount' => $gst['cgst_amount'],
            'sgst_amount' => $gst['sgst_amount'],
            'igst_amount' => $gst['igst_amount'],
            'tax_amount' => $gst['tax_amount'],
            'invoice_total' => $total->toMoney(),
            'retention_percent' => $retentionPercent->round(Decimal::PERCENT_SCALE)->toString(),
            'retention_amount' => $retention->toMoney(),
            'advance_recovery' => $advance->toMoney(),
            'tds_percent' => $tdsPercent->round(Decimal::PERCENT_SCALE)->toString(),
            'tds_amount' => $tds->toMoney(),
            'other_deductions' => $other->toMoney(),
            'net_payable' => $net->toMoney(),
        ])->save();

        $this->assertAdvance($invoice, $advance);
    }

    private function assertAdvance(ClientInvoice $invoice, Decimal $advance): void
    {
        if ($advance->isZero()) {
            return;
        }
        $balance = $this->advanceBalance($invoice->project_id, $invoice->client_id, $invoice->id);
        if ($advance->greaterThan($balance)) {
            throw ValidationException::withMessages(['advance_recovery' => 'The advance recovery cannot exceed the client advance not yet adjusted ('.$balance->toMoney().').']);
        }
    }

    /**
     * Σ current quantity per BOQ line on the project's certified bills (excluding one bill).
     *
     * @param  list<string>  $uids
     * @return array<string, Decimal>
     */
    private function certifiedQuantities(int $projectId, array $uids, ?int $exceptInvoiceId = null): array
    {
        if ($uids === []) {
            return [];
        }
        $rows = ClientInvoiceItem::query()
            ->whereIn('boq_line_uid', $uids)
            ->whereHas('invoice', fn ($q) => $q->where('project_id', $projectId)->whereIn('status', ClientInvoiceStatus::certifiedStates())
                ->when($exceptInvoiceId, fn ($w) => $w->whereKeyNot($exceptInvoiceId)))
            ->get(['boq_line_uid', 'current_qty']);

        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->boq_line_uid] = ($totals[$row->boq_line_uid] ?? Decimal::zero())->plus($row->current_qty);
        }

        return $totals;
    }

    /**
     * Executed quantity per BOQ line from the progress ledger (signed sum) up to a date.
     *
     * @param  list<string>  $uids
     * @return array<string, Decimal>
     */
    private function executedQuantities(int $projectId, array $uids, string $upTo): array
    {
        if ($uids === []) {
            return [];
        }
        $rows = ProgressEntry::query()->where('project_id', $projectId)->whereIn('boq_line_uid', $uids)
            ->whereDate('entry_date', '<=', $upTo)->get(['boq_line_uid', 'quantity']);

        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->boq_line_uid] = ($totals[$row->boq_line_uid] ?? Decimal::zero())->plus($row->quantity);
        }

        return $totals;
    }

    /** Rebuilds received_amount and the paid status (called by payments and retention releases). */
    public function refreshReceived(ClientInvoice $invoice): void
    {
        $this->payables->refresh($invoice);
    }
}
