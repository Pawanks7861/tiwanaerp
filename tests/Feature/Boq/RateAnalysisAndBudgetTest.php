<?php

use App\Enums\Boq\BudgetStatus;
use App\Enums\Boq\RateAnalysisStatus;
use App\Enums\CostHead;
use App\Models\Boq\ProjectBudget;
use App\Models\Boq\RateAnalysis;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Services\Boq\BoqService;
use App\Services\Boq\ProjectBudgetService;
use App\Services\Boq\RateAnalysisService;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsProjectPlanningData;

uses(BuildsProjectPlanningData::class);

beforeEach(function () {
    $this->setUpProjectTeam();
});

function rateBoqLine($test, $boq, array $row)
{
    return $test->inCompany($test->company, function () use ($boq, $row) {
        $section = $boq->sections()->first();
        app(BoqService::class)->syncItems($boq, [$row + ['boq_section_id' => $section->id, 'unit_id' => $section->boq->items()->value('unit_id')]], [], true);

        return $boq->items()->latest('id')->first();
    });
}

test('a rate analysis is numbered and calculated on the server', function () {
    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.rate-analyses.store', $this->project), $this->sampleRateAnalysisData() + ['unit_rate' => '1.0000'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $analysis = $this->inCompany($this->company, fn () => RateAnalysis::query()->with('items')->sole());

    expect($analysis->code)->toBe('RA-0001')
        ->and($analysis->project_id)->toBe($this->project->id)
        ->and($analysis->status)->toBe(RateAnalysisStatus::Draft)
        ->and($analysis->material_cost)->toBe('20500.00')
        ->and($analysis->other_cost)->toBe('333.33')
        ->and($analysis->overhead_amount)->toBe('2458.33')
        ->and($analysis->profit_amount)->toBe('2028.12')
        ->and($analysis->total_cost)->toBe('29069.78')
        ->and($analysis->unit_rate)->toBe('2906.9780')
        ->and($analysis->items->pluck('amount')->all())->toBe(['20500.00', '2550.00', '1200.00', '333.33']);
});

test('an output quantity of zero is rejected', function () {
    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.rate-analyses.store', $this->project), ['output_quantity' => '0'] + $this->sampleRateAnalysisData())
        ->assertSessionHasErrors('output_quantity');

    expect(fn () => $this->inCompany($this->company, fn () => app(RateAnalysisService::class)
        ->save($this->project, ['output_quantity' => '0'] + $this->sampleRateAnalysisData())))
        ->toThrow(InvalidArgumentException::class);
});

test('rate analysis resources must be masters of the same company', function () {
    $data = $this->sampleRateAnalysisData();
    $otherCompany = $this->createCompany();
    $foreign = $this->inCompany($otherCompany, fn () => Material::query()->create([
        'code' => 'FOREIGN', 'name' => 'Foreign cement', 'unit_id' => Unit::query()->value('id'),
    ]));
    $data['items'][0]['material_id'] = $foreign->id;

    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.rate-analyses.store', $this->project), $data)
        ->assertSessionHasErrors('items.0.material_id');
});

test('approved rate analyses are locked', function () {
    $analysis = $this->makeApprovedRateAnalysis();

    expect($analysis->status)->toBe(RateAnalysisStatus::Approved)
        ->and($analysis->approved_by)->toBe($this->billing->id);

    $this->actingInCompany($this->billing, $this->company)
        ->put(route('projects.rate-analyses.update', [$this->project, $analysis]), $this->sampleRateAnalysisData())
        ->assertForbidden();

    $this->inCompany($this->company, function () use ($analysis) {
        expect(fn () => app(RateAnalysisService::class)->save($this->project, $this->sampleRateAnalysisData(), $analysis->fresh()))
            ->toThrow(ValidationException::class)
            ->and(fn () => $analysis->fresh()->items()->first()->update(['rate' => '1']))->toThrow(ValidationException::class)
            ->and(fn () => app(RateAnalysisService::class)->delete($analysis->fresh()))->toThrow(ValidationException::class);
    });
});

test('rate analyses need cost access and the rate_analysis permissions', function () {
    $this->actingInCompany($this->pm, $this->company)->get(route('projects.rate-analyses.index', $this->project))->assertOk();
    $this->actingInCompany($this->pm, $this->company)
        ->post(route('projects.rate-analyses.store', $this->project), $this->sampleRateAnalysisData())
        ->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.rate-analyses.index', $this->project))->assertForbidden();
});

test('applying an approved analysis snapshots its rates onto the BOQ line', function () {
    $analysis = $this->makeApprovedRateAnalysis();
    $boq = $this->makeBoq();

    $item = rateBoqLine($this, $boq, ['name' => 'M20 footing', 'quantity' => '2', 'rate_analysis_id' => $analysis->id, 'client_rate' => '1']);

    expect($item->rate_analysis_id)->toBe($analysis->id)
        ->and($item->material_rate)->toBe('2083.3330')
        ->and($item->labour_rate)->toBe('255.0000')
        ->and($item->equipment_rate)->toBe('120.0000')
        ->and($item->subcontract_rate)->toBe('0.0000')
        ->and($item->cost_rate)->toBe('2458.3330')
        ->and($item->cost_amount)->toBe('4916.67')
        ->and($item->margin_percent)->toBe('18.2500')
        ->and($item->client_rate)->toBe('2906.9780')
        ->and($item->client_amount)->toBe('5813.96');

    $this->inCompany($this->company, fn () => RateAnalysis::query()->whereKey($analysis->id)->update(['unit_rate' => '9999.0000']));
    expect($this->inCompany($this->company, fn () => $item->fresh()->client_rate))->toBe('2906.9780');
});

test('draft analyses and analyses of other projects cannot be applied', function () {
    $mall = $this->makeTeamProject('Mall');
    $foreign = $this->makeApprovedRateAnalysis($mall);
    $draft = $this->inCompany($this->company, fn () => app(RateAnalysisService::class)->save($this->project, $this->sampleRateAnalysisData()));
    $boq = $this->makeBoq();
    $section = $this->inCompany($this->company, fn () => $boq->sections()->sole());

    foreach ([$foreign, $draft] as $analysis) {
        $this->actingInCompany($this->billing, $this->company)
            ->put(route('projects.boqs.items.save', [$this->project, $boq]), [
                'rows' => [['boq_section_id' => $section->id, 'unit_id' => $this->unitId(), 'name' => 'X', 'quantity' => '1', 'rate_analysis_id' => $analysis->id]],
                'deleted_ids' => [],
            ])
            ->assertSessionHasErrors('rows.0.rate_analysis_id');
    }
});

test('a budget is generated from the approved BOQ and its heads add up exactly', function () {
    $boq = $this->approveBoq($this->makeBoq());

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.budget.generate', $this->project), ['boq_id' => $boq->id])
        ->assertSessionHasNoErrors();

    $budget = $this->inCompany($this->company, fn () => ProjectBudget::query()->with('lines')->sole());
    $byHead = fn (string $code) => $budget->lines->filter(fn ($l) => str_starts_with($l->description, $code))
        ->mapWithKeys(fn ($l) => [$l->cost_head->value => $l->amount])->sortKeys()->all();

    expect($budget->version)->toBe(1)
        ->and($budget->status)->toBe(BudgetStatus::Draft)
        ->and($budget->boq_id)->toBe($boq->id)
        ->and($budget->total_amount)->toBe('75029.39')
        ->and($budget->total_amount)->toBe($boq->total_cost_amount)
        ->and($byHead('A.1'))->toBe([CostHead::Equipment->value => '3750.00', CostHead::Labour->value => '15003.13', CostHead::Material->value => '56256.25'])
        ->and($byHead('A.2'))->toBe([CostHead::Labour->value => '10.00', CostHead::Material->value => '10.01']);
});

test('a budget cannot be generated from a BOQ that is not approved', function () {
    $boq = $this->makeBoq();

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.budget.generate', $this->project), ['boq_id' => $boq->id])
        ->assertSessionHasErrors('boq_id');
});

test('changing an approved budget creates a new version and approval supersedes the old one', function () {
    $boq = $this->approveBoq($this->makeBoq());
    $v1 = $this->inCompany($this->company, fn () => app(ProjectBudgetService::class)->generateFromBoq($this->project, $boq));

    $this->actingInCompany($this->director, $this->company)
        ->post(route('projects.budget.approve', [$this->project, $v1]))
        ->assertSessionHasNoErrors();

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.budget.lines.store', $this->project), ['cost_head' => 'overhead', 'description' => 'Site office', 'amount' => '25000.50'])
        ->assertSessionHasNoErrors();

    [$v1, $v2] = $this->inCompany($this->company, fn () => ProjectBudget::query()->orderBy('version')->get()->all());
    expect($v1->status)->toBe(BudgetStatus::Approved)
        ->and($v1->total_amount)->toBe('75029.39')
        ->and($v2->version)->toBe(2)
        ->and($v2->status)->toBe(BudgetStatus::Draft)
        ->and($v2->boq_id)->toBe($boq->id)
        ->and($v2->total_amount)->toBe('100029.89');

    $line = $this->inCompany($this->company, fn () => $v1->lines()->first());
    $this->actingInCompany($this->admin, $this->company)
        ->delete(route('projects.budget.lines.destroy', [$this->project, $line]))
        ->assertSessionHasErrors('budget');

    $this->actingInCompany($this->director, $this->company)
        ->post(route('projects.budget.approve', [$this->project, $v2]))
        ->assertSessionHasNoErrors();

    expect($this->inCompany($this->company, fn () => $v1->fresh()->status))->toBe(BudgetStatus::Superseded)
        ->and($this->inCompany($this->company, fn () => $v2->fresh()->status))->toBe(BudgetStatus::Approved);
});

test('budgets are hidden from users without budget and cost access', function () {
    $this->actingInCompany($this->pm, $this->company)->get(route('projects.budget.index', $this->project))->assertOk();
    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.budget.index', $this->project))->assertForbidden();
    $this->actingInCompany($this->pm, $this->company)
        ->post(route('projects.budget.lines.store', $this->project), ['cost_head' => 'other', 'description' => 'X', 'amount' => '1'])
        ->assertForbidden();
});
