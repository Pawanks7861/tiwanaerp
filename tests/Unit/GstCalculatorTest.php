<?php

use App\Enums\Procurement\TaxType;
use App\Models\Masters\TaxRate;
use App\Services\Tax\GstCalculator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

function gst18(): TaxRate
{
    return new TaxRate(['name' => 'GST 18%', 'rate' => '18', 'cgst_rate' => '9', 'sgst_rate' => '9', 'igst_rate' => '18', 'cess_rate' => '0']);
}

test('the tax type is intra-state only when the vendor state equals the place of supply', function () {
    $gst = new GstCalculator;

    expect($gst->taxType('27', '27'))->toBe(TaxType::Intra)
        ->and($gst->taxType('29', '27'))->toBe(TaxType::Inter);
});

test('a vendor without a GST state or a missing place of supply is rejected', function () {
    $gst = new GstCalculator;

    expect(fn () => $gst->taxType(null, '27'))->toThrow(ValidationException::class)
        ->and(fn () => $gst->taxType('27', ''))->toThrow(ValidationException::class);
});

test('an intra-state line splits GST into CGST and SGST, rounded to the paisa per line', function () {
    // 3 × 333.33 = 999.99; 2.5 % discount = 24.99975 → 25.00; taxable 974.99;
    // CGST 9 % = 87.7491 → 87.75; SGST 87.75; amount 974.99 + 175.50 = 1150.49.
    $line = (new GstCalculator)->line('3', '333.33', '2.5', gst18(), TaxType::Intra);

    expect($line)->toBe([
        'base_amount' => '999.99',
        'discount_amount' => '25.00',
        'taxable_amount' => '974.99',
        'cgst_rate' => '9.0000',
        'cgst_amount' => '87.75',
        'sgst_rate' => '9.0000',
        'sgst_amount' => '87.75',
        'igst_rate' => '0.0000',
        'igst_amount' => '0.00',
        'amount' => '1150.49',
    ]);
});

test('an inter-state line charges IGST only', function () {
    // taxable 974.99 × 18 % = 175.4982 → 175.50.
    $line = (new GstCalculator)->line('3', '333.33', '2.5', gst18(), TaxType::Inter);

    expect($line['cgst_amount'])->toBe('0.00')
        ->and($line['sgst_amount'])->toBe('0.00')
        ->and($line['igst_rate'])->toBe('18.0000')
        ->and($line['igst_amount'])->toBe('175.50')
        ->and($line['amount'])->toBe('1150.49');
});

test('half-paisa CGST amounts round half up on each line', function () {
    // 1 × 72.50 = 72.50; 9 % = 6.525 → 6.53 (HALF_UP, not banker's rounding).
    $line = (new GstCalculator)->line('1', '72.50', '0', gst18(), TaxType::Intra);

    expect($line['cgst_amount'])->toBe('6.53')
        ->and($line['sgst_amount'])->toBe('6.53')
        ->and($line['amount'])->toBe('85.56');
});

test('a line without a tax rate has no GST', function () {
    $line = (new GstCalculator)->line('2', '150', null, null, TaxType::Intra);

    expect($line['taxable_amount'])->toBe('300.00')
        ->and($line['cgst_amount'])->toBe('0.00')
        ->and($line['amount'])->toBe('300.00');
});

test('order totals sum the rounded lines, add untaxed charges and round off to the rupee', function () {
    $gst = new GstCalculator;
    $lines = [
        $gst->line('3', '333.33', '2.5', gst18(), TaxType::Intra),   // 974.99 + 87.75 + 87.75
        $gst->line('1', '72.50', '0', gst18(), TaxType::Intra),      // 72.50 + 6.53 + 6.53
    ];

    // taxable 1047.49; CGST 94.28; SGST 94.28; freight 100.10; other 0 → 1336.15 → 1336.00, round off −0.15.
    expect($gst->orderTotals($lines, '100.10', null))->toBe([
        'subtotal' => '1072.49',
        'discount_amount' => '25.00',
        'taxable_amount' => '1047.49',
        'cgst_amount' => '94.28',
        'sgst_amount' => '94.28',
        'igst_amount' => '0.00',
        'freight_amount' => '100.10',
        'other_charges' => '0.00',
        'round_off' => '-0.15',
        'grand_total' => '1336.00',
    ]);
});

test('a quotation line uses the total GST rate', function () {
    expect((new GstCalculator)->quotationLine('3', '333.33', '2.5', gst18()))->toBe([
        'base_amount' => '999.99',
        'discount_amount' => '25.00',
        'taxable_amount' => '974.99',
        'tax_percent' => '18.0000',
        'tax_amount' => '175.50',
        'amount' => '1150.49',
    ]);
});
