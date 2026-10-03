<?php

use App\Services\Boq\BoqCalculator;
use App\Services\Boq\RateAnalysisCalculator;

test('a BOQ line is calculated with exact decimals', function () {
    $line = BoqCalculator::line([
        'quantity' => '12.5',
        'material_rate' => '4500.5',
        'labour_rate' => '1200.25',
        'equipment_rate' => '300',
        'subcontract_rate' => '0',
        'margin_percent' => '15',
    ]);

    expect($line)->toBe([
        'quantity' => '12.5000',
        'material_rate' => '4500.5000',
        'labour_rate' => '1200.2500',
        'equipment_rate' => '300.0000',
        'subcontract_rate' => '0.0000',
        'cost_rate' => '6000.7500',
        'cost_amount' => '75009.38',
        'margin_percent' => '15.0000',
        'selling_rate' => '6900.8625',
        'client_rate' => '6900.8625',
        'client_amount' => '86260.78',
    ]);
});

test('an explicit client rate overrides the selling rate', function () {
    $line = BoqCalculator::line(['quantity' => '12.5', 'material_rate' => '6000.75', 'margin_percent' => '15', 'client_rate' => '7000']);

    expect($line['selling_rate'])->toBe('6900.8625')
        ->and($line['client_rate'])->toBe('7000.0000')
        ->and($line['client_amount'])->toBe('87500.00');
});

test('BOQ amounts round half up to paise', function () {
    $line = BoqCalculator::line(['quantity' => '1', 'material_rate' => '10.005']);

    expect($line['cost_amount'])->toBe('10.01')
        ->and($line['client_amount'])->toBe('10.01');
});

test('missing inputs count as zero', function () {
    $line = BoqCalculator::line(['quantity' => '5']);

    expect($line['cost_rate'])->toBe('0.0000')
        ->and($line['cost_amount'])->toBe('0.00')
        ->and($line['client_amount'])->toBe('0.00');
});

test('implied margin compares a client rate with a cost rate', function () {
    expect(BoqCalculator::impliedMargin('100.0000', '115.5000'))->toBe('15.5000')
        ->and(BoqCalculator::impliedMargin('0', '10'))->toBe('0.0000')
        ->and(BoqCalculator::impliedMargin('2458.3330', '2906.9780'))->toBe('18.2500');
});

test('a rate analysis is calculated with wastage, overhead and profit', function () {
    $result = RateAnalysisCalculator::calculate(
        ['output_quantity' => '10', 'overhead_percent' => '10', 'profit_percent' => '7.5'],
        [
            ['resource_type' => 'material', 'quantity' => '4', 'wastage_percent' => '2.5', 'rate' => '5000'],
            ['resource_type' => 'labour', 'quantity' => '3', 'rate' => '850'],
            ['resource_type' => 'equipment', 'quantity' => '1', 'rate' => '1200'],
            ['resource_type' => 'other', 'quantity' => '1', 'rate' => '333.333'],
        ],
    );

    expect($result['items'][0])->toBe(['effective_quantity' => '4.1000', 'amount' => '20500.00'])
        ->and($result['material_cost'])->toBe('20500.00')
        ->and($result['labour_cost'])->toBe('2550.00')
        ->and($result['equipment_cost'])->toBe('1200.00')
        ->and($result['subcontract_cost'])->toBe('0.00')
        ->and($result['other_cost'])->toBe('333.33')
        ->and($result['direct_cost'])->toBe('24583.33')
        ->and($result['overhead_amount'])->toBe('2458.33')
        ->and($result['profit_amount'])->toBe('2028.12')
        ->and($result['total_cost'])->toBe('29069.78')
        ->and($result['unit_rate'])->toBe('2906.9780');
});

test('a rate analysis needs an output quantity above zero', function (string $quantity) {
    RateAnalysisCalculator::calculate(['output_quantity' => $quantity], []);
})->with(['0', '0.00001', '-1'])->throws(InvalidArgumentException::class);

test('per-unit rates fold other resources into the material rate', function () {
    expect(RateAnalysisCalculator::perUnitRates([
        'output_quantity' => '10',
        'material_cost' => '20500.00',
        'labour_cost' => '2550.00',
        'equipment_cost' => '1200.00',
        'subcontract_cost' => '0.00',
        'other_cost' => '333.33',
    ]))->toBe([
        'material_rate' => '2083.3330',
        'labour_rate' => '255.0000',
        'equipment_rate' => '120.0000',
        'subcontract_rate' => '0.0000',
    ]);
});
