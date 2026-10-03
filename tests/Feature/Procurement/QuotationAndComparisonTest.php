<?php

use App\Enums\Procurement\BidComparisonStatus;
use App\Enums\Procurement\RfqStatus;
use App\Models\Masters\TaxRate;
use App\Models\Procurement\BidComparison;
use App\Models\Procurement\Rfq;
use App\Models\Procurement\VendorQuotation;
use App\Services\Procurement\BidComparisonService;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsProcurementData;

uses(BuildsProcurementData::class);

beforeEach(function () {
    $this->setUpProcurement();
    $this->rfq = $this->sentRfq($this->approvedMr());
});

function quotationPayload($test, $vendor, array $rates, array $extra = []): array
{
    return $test->inCompany($test->company, function () use ($test, $vendor, $rates, $extra) {
        $gst18 = TaxRate::query()->where('name', 'GST 18%')->value('id');
        $items = $test->rfq->items()->orderBy('sort_order')->get()->values()
            ->map(fn ($item, $i) => ['rfq_item_id' => $item->id, 'rate' => $rates[$i] ?? null, 'discount_percent' => '0', 'tax_rate_id' => $gst18])->all();

        return $extra + ['vendor_id' => $vendor->id, 'quotation_date' => now()->toDateString(), 'delivery_days' => 7, 'items' => $items];
    });
}

function comparisonOf($test): BidComparison
{
    return $test->inCompany($test->company, fn () => BidComparison::query()->where('rfq_id', $test->rfq->id)->firstOrFail());
}

test('the quotation form is pre-filled from the RFQ items with the material tax rate', function () {
    $this->actingInCompany($this->purchaser, $this->company)
        ->get(route('projects.rfqs.quotations.create', [$this->project, $this->rfq, 'vendor' => $this->vendorIntra->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Rfqs/Quotation')
            ->has('items', 2)
            ->where('items.0.quantity', '100.0000')
            ->where('items.0.tax_rate_id', $this->cement->tax_rate_id)
            ->has('vendors', 2)
            ->where('preselectVendor', $this->vendorIntra->id));
});

test('quotation totals are recomputed on the server and forged amounts are ignored', function () {
    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.rfqs.quotations.store', [$this->project, $this->rfq]), quotationPayload($this, $this->vendorIntra, ['400', '62000'], [
            'grand_total' => '1.00', 'tax_amount' => '0', 'freight_amount' => '500.50',
        ]))
        ->assertSessionHasNoErrors();

    $quotation = $this->inCompany($this->company, fn () => VendorQuotation::query()->with('items')->firstOrFail());
    $lines = $quotation->items->sortBy('rfq_item_id')->values();

    expect($lines[0]->taxable_amount)->toBe('40000.00')
        ->and($lines[0]->tax_amount)->toBe('7200.00')
        ->and($lines[0]->amount)->toBe('47200.00')
        ->and($lines[1]->taxable_amount)->toBe('310000.00')
        ->and($lines[1]->tax_amount)->toBe('55800.00')
        ->and($quotation->taxable_amount)->toBe('350000.00')
        ->and($quotation->tax_amount)->toBe('63000.00')
        ->and($quotation->freight_amount)->toBe('500.50')
        ->and($quotation->grand_total)->toBe('413500.50')
        ->and($this->inCompany($this->company, fn () => Rfq::query()->find($this->rfq->id)->status))->toBe(RfqStatus::QuotesReceived);
});

test('each invited vendor quotes once; uninvited vendors and foreign RFQ lines are rejected', function () {
    $this->quote($this->rfq, $this->vendorIntra, ['400', '62000']);
    $as = $this->actingInCompany($this->purchaser, $this->company);

    $as->post(route('projects.rfqs.quotations.store', [$this->project, $this->rfq]), quotationPayload($this, $this->vendorIntra, ['390', '61000']))
        ->assertSessionHasErrors('vendor_id');
    $as->post(route('projects.rfqs.quotations.store', [$this->project, $this->rfq]), quotationPayload($this, $this->vendorThird, ['390', '61000']))
        ->assertSessionHasErrors('vendor_id');

    $payload = quotationPayload($this, $this->vendorInter, ['390']);
    $payload['items'][0]['rfq_item_id'] = 999999;
    $as->post(route('projects.rfqs.quotations.store', [$this->project, $this->rfq]), $payload)->assertSessionHasErrors('items.0.rfq_item_id');

    $as->post(route('projects.rfqs.quotations.store', [$this->project, $this->rfq]), quotationPayload($this, $this->vendorInter, [null, null]))
        ->assertSessionHasErrors('items');

    expect($this->inCompany($this->company, fn () => VendorQuotation::query()->count()))->toBe(1);
});

test('the comparison matrix shows every quotation and selects nothing automatically', function () {
    $this->quote($this->rfq, $this->vendorIntra, ['400', '62000']);
    $this->quote($this->rfq, $this->vendorInter, ['380', '64000']);
    $as = $this->actingInCompany($this->purchaser, $this->company);

    $as->get(route('projects.rfqs.comparison', [$this->project, $this->rfq]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Rfqs/Comparison')
            ->has('items', 2)
            ->has('quotations', 2)
            ->where('comparison', null)
            ->where('can.edit', true));

    $as->put(route('projects.rfqs.comparison.save', [$this->project, $this->rfq]), ['justification' => 'Draft notes'])->assertSessionHasNoErrors();

    $comparison = comparisonOf($this);
    expect($comparison->selected_vendor_quotation_id)->toBeNull()
        ->and($comparison->status)->toBe(BidComparisonStatus::Draft);

    $as->post(route('projects.rfqs.comparison.submit', [$this->project, $this->rfq]))->assertSessionHasErrors('comparison');
});

test('a quotation of another RFQ cannot be selected', function () {
    $mine = $this->quote($this->rfq, $this->vendorIntra, ['400', '62000']);
    $otherRfq = $this->sentRfq($this->approvedMr());
    $foreign = $this->quote($otherRfq, $this->vendorInter, ['1', '1']);

    $this->actingInCompany($this->purchaser, $this->company)
        ->put(route('projects.rfqs.comparison.save', [$this->project, $this->rfq]), [
            'selected_vendor_quotation_id' => $foreign->id, 'selection_basis' => 'lowest_price', 'justification' => 'Cheapest',
        ])
        ->assertSessionHasErrors('selected_vendor_quotation_id');

    expect($mine->id)->not->toBe($foreign->id);
});

test('submission locks the comparison; the submitter cannot approve and the director approval selects the quotation', function () {
    $intra = $this->quote($this->rfq, $this->vendorIntra, ['400', '62000']);
    $inter = $this->quote($this->rfq, $this->vendorInter, ['380', '64000']);

    $admin = $this->actingInCompany($this->admin, $this->company);
    $admin->put(route('projects.rfqs.comparison.save', [$this->project, $this->rfq]), [
        'selected_vendor_quotation_id' => $intra->id, 'selection_basis' => 'lowest_price', 'justification' => 'Lowest landed cost overall.',
    ])->assertSessionHasNoErrors();
    $admin->post(route('projects.rfqs.comparison.submit', [$this->project, $this->rfq]))->assertSessionHasNoErrors();

    expect(comparisonOf($this)->status)->toBe(BidComparisonStatus::Submitted)
        ->and($this->inCompany($this->company, fn () => Rfq::query()->find($this->rfq->id)->status))->toBe(RfqStatus::Evaluated);

    // Locked: no edits once submitted, not even by forged requests.
    $admin->put(route('projects.rfqs.comparison.save', [$this->project, $this->rfq]), ['selected_vendor_quotation_id' => $inter->id])->assertForbidden();
    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.rfqs.quotations.store', [$this->project, $this->rfq]), quotationPayload($this, $this->vendorInter, ['1', '1']))
        ->assertForbidden();

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.rfqs.comparison.approve', [$this->project, $this->rfq]))->assertSessionHasErrors('comparison');
    $this->actingInCompany($this->purchaser, $this->company)
        ->post(route('projects.rfqs.comparison.approve', [$this->project, $this->rfq]))->assertForbidden();

    $this->actingInCompany($this->director, $this->company)
        ->post(route('projects.rfqs.comparison.approve', [$this->project, $this->rfq]))->assertSessionHasNoErrors();

    $comparison = comparisonOf($this);
    expect($comparison->status)->toBe(BidComparisonStatus::Approved)
        ->and($comparison->approved_by)->toBe($this->director->id)
        ->and($this->inCompany($this->company, fn () => VendorQuotation::query()->find($intra->id)->is_selected))->toBeTrue()
        ->and($this->inCompany($this->company, fn () => VendorQuotation::query()->find($inter->id)->is_selected))->toBeFalse();

    $this->inCompany($this->company, function () use ($comparison) {
        expect(fn () => BidComparison::query()->find($comparison->id)->forceFill(['justification' => 'forged'])->save())
            ->toThrow(ValidationException::class);
    });
});

test('rejecting a comparison reopens the RFQ for evaluation', function () {
    $intra = $this->quote($this->rfq, $this->vendorIntra, ['400', '62000']);
    $this->inCompany($this->company, function () use ($intra) {
        $service = app(BidComparisonService::class);
        $comparison = $service->forRfq($this->rfq->fresh());
        $service->save($comparison, ['selected_vendor_quotation_id' => $intra->id, 'selection_basis' => 'quality', 'justification' => 'Better brand.']);
        $service->submit($comparison->fresh(), $this->purchaser);
    });

    $director = $this->actingInCompany($this->director, $this->company);
    $director->post(route('projects.rfqs.comparison.reject', [$this->project, $this->rfq]), [])->assertSessionHasErrors('reason');
    $director->post(route('projects.rfqs.comparison.reject', [$this->project, $this->rfq]), ['reason' => 'Get a third quote'])->assertSessionHasNoErrors();

    expect(comparisonOf($this)->status)->toBe(BidComparisonStatus::Rejected)
        ->and(comparisonOf($this)->rejection_reason)->toBe('Get a third quote')
        ->and($this->inCompany($this->company, fn () => Rfq::query()->find($this->rfq->id)->status))->toBe(RfqStatus::QuotesReceived);
});

test('site engineers cannot see quotations or comparisons', function () {
    $this->quote($this->rfq, $this->vendorIntra, ['400', '62000']);
    $as = $this->actingInCompany($this->engineer, $this->company);

    $as->get(route('projects.rfqs.comparison', [$this->project, $this->rfq]))->assertForbidden();
    $as->post(route('projects.rfqs.quotations.store', [$this->project, $this->rfq]), quotationPayload($this, $this->vendorInter, ['1', '1']))->assertForbidden();
});
