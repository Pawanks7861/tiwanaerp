<?php

use App\Enums\Boq\BoqStatus;
use App\Enums\ProjectRole;
use App\Exports\BoqExport;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqSection;
use App\Models\Masters\Unit;
use App\Models\Planning\ProjectTask;
use App\Services\Approval\ApprovalService;
use App\Services\Boq\BoqImportService;
use App\Services\Boq\BoqRevisionService;
use App\Services\Boq\BoqService;
use App\Services\Planning\PlanningService;
use App\Services\Projects\ProjectService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsProjectPlanningData;

uses(BuildsProjectPlanningData::class);

beforeEach(function () {
    $this->setUpProjectTeam();
});

function boqItemsOf($test, Boq $boq)
{
    return $test->inCompany($test->company, fn () => $boq->items()->orderBy('sort_order')->get());
}

function boqCsv(array $rows): UploadedFile
{
    $lines = [implode(',', BoqImportService::HEADINGS)];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn ($v) => str_contains((string) $v, ',') ? '"'.$v.'"' : $v, $row));
    }

    return UploadedFile::fake()->createWithContent('boq.csv', implode("\n", $lines)."\n");
}

test('a BOQ gets its number from the numbering engine and starts as a draft', function () {
    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.boqs.store', $this->project), ['title' => 'Civil works'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.boqs.store', $this->project), ['title' => 'MEP works']);

    $boqs = $this->inCompany($this->company, fn () => Boq::query()->orderBy('id')->get());

    expect($boqs->pluck('boq_number')->all())->toBe(['BOQ-PRJ001-001', 'BOQ-PRJ001-002'])
        ->and($boqs[0]->version)->toBe(1)
        ->and($boqs[0]->status)->toBe(BoqStatus::Draft)
        ->and($boqs[0]->is_current)->toBeFalse()
        ->and($boqs[0]->created_by)->toBe($this->billing->id);
});

test('line amounts and BOQ totals are calculated on the server', function () {
    $boq = $this->makeBoq([]);
    $section = $this->inCompany($this->company, fn () => $boq->sections()->sole());
    $rows = array_map(fn ($line) => $line + [
        'boq_section_id' => $section->id,
        'unit_id' => $this->unitId(),
        'cost_amount' => '1.00',
        'client_amount' => '1.00',
    ], $this->sampleBoqLines());

    $this->actingInCompany($this->billing, $this->company)
        ->put(route('projects.boqs.items.save', [$this->project, $boq]), ['rows' => $rows, 'deleted_ids' => []])
        ->assertSessionHasNoErrors();

    $items = boqItemsOf($this, $boq);
    $boq = $this->inCompany($this->company, fn () => $boq->fresh());

    expect($items)->toHaveCount(2)
        ->and($items[0]->cost_rate)->toBe('6000.7500')
        ->and($items[0]->cost_amount)->toBe('75009.38')
        ->and($items[0]->selling_rate)->toBe('6900.8625')
        ->and($items[0]->client_amount)->toBe('86260.78')
        ->and($items[1]->cost_amount)->toBe('20.01')
        ->and($items[1]->client_rate)->toBe('10.0000')
        ->and($items[1]->client_amount)->toBe('30.00')
        ->and($items[0]->line_uid)->not->toBe($items[1]->line_uid)
        ->and($boq->total_cost_amount)->toBe('75029.39')
        ->and($boq->total_client_amount)->toBe('86290.78');
});

test('lines can only use sections of the same BOQ and units of the same company', function () {
    $boq = $this->makeBoq([]);
    $other = $this->makeBoq([], title: 'Other');
    $foreignSection = $this->inCompany($this->company, fn () => $other->sections()->sole());
    $section = $this->inCompany($this->company, fn () => $boq->sections()->sole());

    $otherCompany = $this->createCompany();
    $foreignUnit = $this->inCompany($otherCompany, fn () => Unit::query()->where('symbol', 'Cum')->value('id'));

    $as = $this->actingInCompany($this->billing, $this->company);
    $as->put(route('projects.boqs.items.save', [$this->project, $boq]), [
        'rows' => [['boq_section_id' => $foreignSection->id, 'unit_id' => $this->unitId(), 'name' => 'X', 'quantity' => '1']],
        'deleted_ids' => [],
    ])->assertSessionHasErrors('rows.0.boq_section_id');

    $as->put(route('projects.boqs.items.save', [$this->project, $boq]), [
        'rows' => [['boq_section_id' => $section->id, 'unit_id' => $foreignUnit, 'name' => 'X', 'quantity' => '1']],
        'deleted_ids' => [],
    ])->assertSessionHasErrors('rows.0.unit_id');

    expect(boqItemsOf($this, $boq))->toHaveCount(0);
});

test('cost fields never reach users without boq.view_costs', function () {
    $quality = $this->createMember($this->company, DefaultRoles::QUALITY_ENGINEER);
    $this->inCompany($this->company, fn () => app(ProjectService::class)
        ->assignMember($this->project, $quality->id, ProjectRole::Quality));
    $boq = $this->makeBoq();

    $this->actingInCompany($quality, $this->company)
        ->get(route('projects.boqs.show', [$this->project, $boq]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Boq/Show')
            ->where('items.0.client_amount', '86260.78')
            ->missing('items.0.cost_rate')
            ->missing('items.0.material_rate')
            ->missing('items.0.margin_percent')
            ->missing('boq.total_cost_amount')
            ->where('can.view_costs', false)
            ->where('analyses', []));

    $this->actingInCompany($this->billing, $this->company)
        ->get(route('projects.boqs.show', [$this->project, $boq]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.cost_rate', '6000.7500')
            ->where('boq.total_cost_amount', '75029.39'));
});

test('editors without cost access keep the stored cost inputs', function () {
    $boq = $this->makeBoq();
    $item = boqItemsOf($this, $boq)->first();

    $this->inCompany($this->company, fn () => app(BoqService::class)->syncItems($boq, [[
        'id' => $item->id,
        'boq_section_id' => $item->boq_section_id,
        'unit_id' => $item->unit_id,
        'name' => 'Excavation in hard soil',
        'quantity' => '10',
        'material_rate' => '1',
        'margin_percent' => '90',
    ]], [], false));

    $item = $this->inCompany($this->company, fn () => $item->fresh());
    expect($item->name)->toBe('Excavation in hard soil')
        ->and($item->material_rate)->toBe('4500.5000')
        ->and($item->margin_percent)->toBe('15.0000')
        ->and($item->cost_amount)->toBe('60007.50')
        ->and($item->client_amount)->toBe('69008.63');
});

test('a BOQ is approved by the project manager and then the director', function () {
    $boq = $this->makeBoq();

    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.boqs.submit', [$this->project, $boq]))
        ->assertSessionHasNoErrors();

    $boq = $this->inCompany($this->company, fn () => $boq->fresh());
    $request = $this->inCompany($this->company, fn () => $boq->pendingApprovalRequest());
    expect($boq->status)->toBe(BoqStatus::Submitted);

    $this->actingInCompany($this->billing, $this->company)
        ->put(route('projects.boqs.items.save', [$this->project, $boq]), ['rows' => [], 'deleted_ids' => []])
        ->assertForbidden();

    $this->actingInCompany($this->director, $this->company)
        ->post(route('approvals.approve', $request))
        ->assertSessionHasErrors('approval');

    $this->actingInCompany($this->pm, $this->company)->post(route('approvals.approve', $request))->assertSessionHasNoErrors();
    expect($this->inCompany($this->company, fn () => $boq->fresh()->status))->toBe(BoqStatus::Submitted);

    $this->actingInCompany($this->director, $this->company)->post(route('approvals.approve', $request))->assertSessionHasNoErrors();

    $boq = $this->inCompany($this->company, fn () => $boq->fresh());
    expect($boq->status)->toBe(BoqStatus::Approved)
        ->and($boq->is_current)->toBeTrue()
        ->and($boq->approved_by)->toBe($this->director->id)
        ->and($boq->approved_at)->not->toBeNull();
});

test('a rejected BOQ can be edited again and a sent-back BOQ returns to draft', function () {
    $boq = $this->makeBoq();
    $this->inCompany($this->company, function () use ($boq) {
        app(BoqService::class)->submit($boq, $this->billing);
        app(ApprovalService::class)->reject($boq->fresh()->pendingApprovalRequest(), $this->pm, 'Rates too high');
    });
    expect($this->inCompany($this->company, fn () => $boq->fresh()->status))->toBe(BoqStatus::Rejected);

    $this->actingInCompany($this->billing, $this->company)
        ->put(route('projects.boqs.update', [$this->project, $boq]), ['title' => 'Civil works (rev rates)'])
        ->assertSessionHasNoErrors();

    $this->inCompany($this->company, function () use ($boq) {
        app(BoqService::class)->submit($boq->fresh(), $this->billing);
        app(ApprovalService::class)->sendBack($boq->fresh()->pendingApprovalRequest(), $this->pm, 'Split item A.1');
    });
    expect($this->inCompany($this->company, fn () => $boq->fresh()->status))->toBe(BoqStatus::Draft);
});

test('an empty BOQ cannot be submitted', function () {
    $boq = $this->makeBoq([]);

    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.boqs.submit', [$this->project, $boq]))
        ->assertSessionHasErrors('boq');
});

test('approved BOQs are locked in the services and models, not only in policies', function () {
    $boq = $this->approveBoq($this->makeBoq());
    $item = boqItemsOf($this, $boq)->first();

    $this->inCompany($this->company, function () use ($boq, $item) {
        expect(fn () => app(BoqService::class)->syncItems($boq, [], [$item->id], true))->toThrow(ValidationException::class)
            ->and(fn () => app(BoqService::class)->update($boq, ['title' => 'Changed']))->toThrow(ValidationException::class)
            ->and(fn () => $item->fresh()->update(['name' => 'Changed']))->toThrow(ValidationException::class)
            ->and(fn () => $boq->fresh()->forceFill(['total_client_amount' => '1.00'])->save())->toThrow(ValidationException::class)
            ->and(fn () => $boq->fresh()->delete())->toThrow(ValidationException::class)
            ->and(fn () => BoqSection::query()->where('boq_id', $boq->id)->first()->delete())->toThrow(ValidationException::class);
    });

    $this->admin->forceFill(['is_super_admin' => true])->save();
    $this->actingInCompany($this->admin, $this->company)
        ->put(route('projects.boqs.items.save', [$this->project, $boq]), ['rows' => [], 'deleted_ids' => [$item->id]])
        ->assertSessionHasErrors('boq');
    $this->actingInCompany($this->admin, $this->company)
        ->get(route('projects.boqs.show', [$this->project, $boq]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.update', false)
            ->where('can.submit', false)
            ->where('can.delete', false)
            ->where('can.import', false)
            ->where('can.revise', true));

    expect(boqItemsOf($this, $boq))->toHaveCount(2);
});

test('revising an approved BOQ clones it into the next version with the same line ids', function () {
    $v1 = $this->approveBoq($this->makeBoq());

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.boqs.revise', [$this->project, $v1]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $v2 = $this->inCompany($this->company, fn () => Boq::query()->where('version', 2)->sole());
    $oldItems = boqItemsOf($this, $v1);
    $newItems = boqItemsOf($this, $v2);

    expect($v2->boq_number)->toBe($v1->boq_number)
        ->and($v2->parent_boq_id)->toBe($v1->id)
        ->and($v2->status)->toBe(BoqStatus::Draft)
        ->and($v2->is_current)->toBeFalse()
        ->and($v2->total_client_amount)->toBe('86290.78')
        ->and($newItems->pluck('line_uid')->all())->toBe($oldItems->pluck('line_uid')->all())
        ->and($newItems->pluck('id')->intersect($oldItems->pluck('id')))->toBeEmpty()
        ->and($newItems->pluck('client_amount')->all())->toBe($oldItems->pluck('client_amount')->all())
        ->and($this->inCompany($this->company, fn () => $v2->sections()->count()))->toBe(1)
        ->and($this->inCompany($this->company, fn () => $v1->fresh()->is_current))->toBeTrue();

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.boqs.revise', [$this->project, $v1]))
        ->assertSessionHasErrors('boq');
});

test('approving a revision supersedes the previous version and moves task links', function () {
    $v1 = $this->approveBoq($this->makeBoq());
    $oldItem = boqItemsOf($this, $v1)->first();
    $task = $this->inCompany($this->company, fn () => app(PlanningService::class)->saveTask($this->project, [
        'name' => 'Excavation', 'boq_item_id' => $oldItem->id,
    ]));

    $v2 = $this->inCompany($this->company, fn () => app(BoqRevisionService::class)->revise($v1));
    $newItem = boqItemsOf($this, $v2)->first();
    $this->inCompany($this->company, fn () => app(BoqService::class)->syncItems($v2, [[
        'id' => $newItem->id, 'boq_section_id' => $newItem->boq_section_id, 'unit_id' => $newItem->unit_id,
        'name' => 'Excavation', 'quantity' => '20', 'material_rate' => '4500.5', 'labour_rate' => '1200.25',
        'equipment_rate' => '300', 'margin_percent' => '15',
    ]], [], true));
    $this->approveBoq($v2);

    [$v1, $v2, $task] = $this->inCompany($this->company, fn () => [$v1->fresh(), $v2->fresh(), ProjectTask::query()->find($task->id)]);

    expect($v1->status)->toBe(BoqStatus::Revised)
        ->and($v1->is_current)->toBeFalse()
        ->and($v2->status)->toBe(BoqStatus::Approved)
        ->and($v2->is_current)->toBeTrue()
        ->and($v2->total_client_amount)->toBe('138047.25')
        ->and($task->boq_item_id)->toBe($newItem->id)
        ->and($this->inCompany($this->company, fn () => BoqItem::query()->find($task->boq_item_id)->line_uid))->toBe($oldItem->line_uid);
});

test('only the current approved BOQ can be revised', function () {
    $draft = $this->makeBoq();

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('projects.boqs.revise', [$this->project, $draft]))
        ->assertForbidden();

    $this->inCompany($this->company, fn () => app(BoqRevisionService::class)->revise($draft));
})->throws(ValidationException::class);

test('a valid Excel file is imported into sections and lines', function () {
    $boq = $this->makeBoq([]);

    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.boqs.import', [$this->project, $boq]), ['file' => boqCsv([
            ['A', 'Earthwork', '', '', 'A.3', 'Backfilling', '', '', 'cum', '100', '50', '25.5', '', '', '10', ''],
            ['B', 'Concrete', 'B1', 'Footings', 'B1.1', 'M20 footing', 'With vibration, curing', '', 'Cubic Metre', '12.345', '4000', '900', '150', '', '', '6000'],
        ])])
        ->assertSessionHasNoErrors();

    $items = boqItemsOf($this, $boq);
    $sections = $this->inCompany($this->company, fn () => $boq->sections()->orderBy('id')->get());

    expect($items)->toHaveCount(2)
        ->and($sections->pluck('name')->all())->toBe(['Earthwork', 'Concrete', 'Footings'])
        ->and($sections[2]->parent_id)->toBe($sections[1]->id)
        ->and($items[0]->boq_section_id)->toBe($sections[0]->id)
        ->and($items[0]->cost_rate)->toBe('75.5000')
        ->and($items[0]->client_amount)->toBe('8305.00')
        ->and($items[1]->boq_section_id)->toBe($sections[2]->id)
        ->and($items[1]->quantity)->toBe('12.3450')
        ->and($items[1]->cost_amount)->toBe('62342.25')
        ->and($items[1]->client_amount)->toBe('74070.00')
        ->and($this->inCompany($this->company, fn () => $boq->fresh()->total_client_amount))->toBe('82375.00');
});

test('an import with row errors writes nothing and reports every bad row', function () {
    $boq = $this->makeBoq([]);
    $otherCompany = $this->createCompany();
    $this->inCompany($otherCompany, fn () => Unit::query()->create(['name' => 'Brass', 'symbol' => 'Brass', 'decimal_places' => 2]));

    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.boqs.import', [$this->project, $boq]), ['file' => boqCsv([
            ['A', 'Earthwork', '', '', 'A.1', 'Good line', '', '', 'Cum', '1', '10', '', '', '', '', ''],
            ['A', 'Earthwork', '', '', 'A.2', '', '', '', 'Cum', '1', '10', '', '', '', '', ''],
            ['A', 'Earthwork', '', '', 'A.3', 'Bad unit', '', '', 'Brass', '1', '10', '', '', '', '', ''],
            ['A', 'Earthwork', '', '', 'A.4', 'Bad qty', '', '', 'Cum', 'abc', '10', '', '', '', '', ''],
        ])])
        ->assertSessionHasErrors(['file', 'file_rows.0', 'file_rows.1', 'file_rows.2']);

    $errors = session('errors')->getBag('default');
    expect($errors->first('file_rows.0'))->toStartWith('Row 3:')
        ->and($errors->first('file_rows.1'))->toBe('Row 4: Unit "Brass" is not an active unit of this company.')
        ->and($errors->first('file_rows.2'))->toStartWith('Row 5:')
        ->and(boqItemsOf($this, $boq))->toHaveCount(0)
        ->and($this->inCompany($this->company, fn () => $boq->sections()->count()))->toBe(1);
});

test('an unreadable or wrong file type is rejected', function () {
    $boq = $this->makeBoq([]);

    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.boqs.import', [$this->project, $boq]), ['file' => UploadedFile::fake()->create('boq.pdf', 10)])
        ->assertSessionHasErrors('file');
});

test('the export only contains cost columns for users who may see costs', function () {
    $boq = $this->makeBoq();

    $withCosts = $this->inCompany($this->company, fn () => [(new BoqExport($boq, true))->headings(), (new BoqExport($boq, true))->array()]);
    $withoutCosts = $this->inCompany($this->company, fn () => [(new BoqExport($boq, false))->headings(), (new BoqExport($boq, false))->array()]);

    expect($withCosts[0])->toContain('Cost Amount', 'Margin %')
        ->and($withCosts[1][0])->toHaveCount(count($withCosts[0]))
        ->and(end($withCosts[1])[12])->toBe('75029.39')
        ->and($withoutCosts[0])->not->toContain('Cost Amount', 'Material Rate', 'Margin %')
        ->and($withoutCosts[1][0])->toHaveCount(count($withoutCosts[0]))
        ->and(collect($withoutCosts[1])->flatten()->all())->not->toContain('6000.7500', '75009.38', '75029.39')
        ->and(end($withoutCosts[1]))->toContain('86290.78');

    Excel::fake();
    $this->actingInCompany($this->billing, $this->company)->get(route('projects.boqs.export', [$this->project, $boq]))->assertOk();
    Excel::assertDownloaded('boq-prj001-001-v1.xlsx');

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.boqs.export', [$this->project, $boq]))->assertForbidden();
});

test('BOQs are isolated by project and company', function () {
    $boq = $this->makeBoq();
    $mall = $this->makeTeamProject('Mall');

    $this->actingInCompany($this->admin, $this->company)
        ->get(route('projects.boqs.show', [$mall, $boq]))
        ->assertNotFound();

    $otherCompany = $this->createCompany();
    $outsider = $this->createMember($otherCompany, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($outsider, $otherCompany)
        ->get(route('projects.boqs.show', [$this->project, $boq]))
        ->assertNotFound();

    $stranger = $this->createMember($this->company, DefaultRoles::BILLING_ENGINEER);
    $this->actingInCompany($stranger, $this->company)
        ->get(route('projects.boqs.index', $this->project))
        ->assertForbidden();
});

test('a spreadsheet import above the safe cap is rejected before it is parsed', function () {
    expect((int) config('uploads.import_max_kb'))->toBe(10240)
        ->and((int) config('uploads.import_max_kb') * 1024)->toBeLessThan((int) config('uploads.max_file_size_bytes'));

    config(['uploads.import_max_kb' => 1]);
    $boq = $this->makeBoq();

    $this->actingInCompany($this->billing, $this->company)
        ->post(route('projects.boqs.import', [$this->project, $boq]), [
            'file' => UploadedFile::fake()->create('huge.xlsx', 2, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ])
        ->assertSessionHasErrors('file');
});

test('site engineers can view but not create, edit or submit BOQs', function () {
    $boq = $this->makeBoq();
    $as = $this->actingInCompany($this->engineer, $this->company);

    $as->get(route('projects.boqs.index', $this->project))->assertOk();
    $as->post(route('projects.boqs.store', $this->project), ['title' => 'Nope'])->assertForbidden();
    $as->put(route('projects.boqs.items.save', [$this->project, $boq]), ['rows' => [], 'deleted_ids' => []])->assertForbidden();
    $as->post(route('projects.boqs.submit', [$this->project, $boq]))->assertForbidden();
    $as->delete(route('projects.boqs.destroy', [$this->project, $boq]))->assertForbidden();
});
