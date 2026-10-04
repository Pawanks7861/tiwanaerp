<?php

use App\Models\Quality\QualityChecklist;
use App\Models\Quality\QualityChecklistItem;
use App\Models\Quality\QualityInspectionItem;
use App\Support\Permissions\DefaultRoles;
use Tests\Concerns\BuildsQualityData;

uses(BuildsQualityData::class);

beforeEach(function () {
    $this->setUpQuality();
});

function qcPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Brickwork',
        'discipline' => 'civil',
        'activity' => 'Masonry',
        'is_active' => true,
        'items' => [
            ['checkpoint' => 'Line and level', 'acceptance_criteria' => '±5 mm'],
            ['checkpoint' => 'Mortar ratio', 'acceptance_criteria' => '1:6'],
            ['checkpoint' => 'Curing', 'acceptance_criteria' => null],
        ],
    ], $overrides);
}

test('quality engineer creates a checklist with ordered checkpoints', function () {
    $this->actingInCompany($this->qe, $this->company)->post(route('quality.checklists.store'), qcPayload())->assertSessionHasNoErrors();

    $checklist = $this->inCompany($this->company, fn () => QualityChecklist::query()->where('name', 'Brickwork')->firstOrFail());
    expect($checklist->company_id)->toBe($this->company->id)
        ->and($checklist->discipline->value)->toBe('civil')
        ->and($checklist->items()->pluck('checkpoint')->all())->toBe(['Line and level', 'Mortar ratio', 'Curing'])
        ->and($checklist->items()->pluck('sort_order')->all())->toBe([1, 2, 3]);

    $this->actingInCompany($this->qe, $this->company)->get(route('quality.checklists.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Quality/Checklists/Index')->where('checklists.total', 2));
});

test('updating reorders and syncs checkpoints by id without touching existing inspections', function () {
    $inspection = $this->requestInspection();
    $items = $this->checklist->items()->get();
    $first = $items[0];
    $second = $items[1];

    $payload = qcPayload([
        'name' => 'RCC slab pre-pour',
        'items' => [
            ['id' => $second->id, 'checkpoint' => 'Checkpoint 2 (renamed)'],
            ['id' => $first->id, 'checkpoint' => 'Checkpoint 1'],
            ['checkpoint' => 'New checkpoint'],
        ],
    ]);
    $this->actingInCompany($this->qe, $this->company)->put(route('quality.checklists.update', $this->checklist), $payload)->assertSessionHasNoErrors();

    $reloaded = $this->inCompany($this->company, fn () => $this->checklist->fresh()->items()->get());
    expect($reloaded->pluck('checkpoint')->all())->toBe(['Checkpoint 2 (renamed)', 'Checkpoint 1', 'New checkpoint'])
        ->and($reloaded[0]->id)->toBe($second->id)
        ->and(QualityChecklistItem::query()->whereKey($items[5]->id)->exists())->toBeFalse();

    // The inspection keeps its own copy of all 10 original checkpoints.
    $copied = QualityInspectionItem::query()->where('quality_inspection_id', $inspection->id)->orderBy('sort_order')->get();
    expect($copied)->toHaveCount(10)
        ->and($copied[1]->checkpoint)->toBe('Checkpoint 2')
        ->and($copied[5]->quality_checklist_item_id)->toBeNull();
});

test('an inactive checklist cannot be used for a new inspection', function () {
    $this->actingInCompany($this->qe, $this->company)
        ->put(route('quality.checklists.update', $this->checklist), qcPayload(['name' => 'RCC slab pre-pour', 'is_active' => false, 'items' => [['checkpoint' => 'One']]]))
        ->assertSessionHasNoErrors();

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.inspections.store', $this->project), ['quality_checklist_id' => $this->checklist->id])
        ->assertSessionHasErrors('quality_checklist_id');
});

test('checklist names are unique per company, items are required, and other companies may reuse a name', function () {
    $this->actingInCompany($this->qe, $this->company)->post(route('quality.checklists.store'), qcPayload(['name' => 'RCC slab pre-pour']))->assertSessionHasErrors('name');
    $this->actingInCompany($this->qe, $this->company)->post(route('quality.checklists.store'), qcPayload(['items' => []]))->assertSessionHasErrors('items');

    $other = $this->createCompany();
    $otherQe = $this->createMember($other, DefaultRoles::QUALITY_ENGINEER);
    $this->actingInCompany($otherQe, $other)->post(route('quality.checklists.store'), qcPayload(['name' => 'RCC slab pre-pour']))->assertSessionHasNoErrors();
    expect(QualityChecklist::query()->withoutGlobalScopes()->where('name', 'RCC slab pre-pour')->count())->toBe(2);
});

test('checklists are tenant isolated', function () {
    $other = $this->createCompany();
    $otherQe = $this->createMember($other, DefaultRoles::QUALITY_ENGINEER);

    $this->actingInCompany($otherQe, $other)->get(route('quality.checklists.show', $this->checklist))->assertNotFound();
    $this->actingInCompany($otherQe, $other)->put(route('quality.checklists.update', $this->checklist), qcPayload())->assertNotFound();
    $this->actingInCompany($otherQe, $other)->delete(route('quality.checklists.destroy', $this->checklist))->assertNotFound();
    $this->actingInCompany($otherQe, $other)->get(route('quality.checklists.index'))
        ->assertInertia(fn ($page) => $page->where('checklists.total', 0));
});

test('site engineers can read checklists but not maintain them', function () {
    $this->actingInCompany($this->engineer, $this->company)->get(route('quality.checklists.show', $this->checklist))->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.update', false)->where('can.delete', false));
    $this->actingInCompany($this->engineer, $this->company)->post(route('quality.checklists.store'), qcPayload())->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)->put(route('quality.checklists.update', $this->checklist), qcPayload())->assertForbidden();
    $this->actingInCompany($this->billing, $this->company)->get(route('quality.checklists.index'))->assertForbidden();
});

test('an unused checklist can be deleted, a used one only deactivated', function () {
    $this->actingInCompany($this->qe, $this->company)->post(route('quality.checklists.store'), qcPayload())->assertSessionHasNoErrors();
    $unused = $this->inCompany($this->company, fn () => QualityChecklist::query()->where('name', 'Brickwork')->firstOrFail());
    $this->actingInCompany($this->qe, $this->company)->delete(route('quality.checklists.destroy', $unused))->assertSessionHasNoErrors();
    expect(QualityChecklist::query()->withoutGlobalScopes()->whereKey($unused->id)->exists())->toBeFalse();

    $this->requestInspection();
    $superAdmin = $this->createMember($this->company, [], ['is_super_admin' => true]);
    $this->actingInCompany($superAdmin, $this->company)->get(route('quality.checklists.show', $this->checklist))
        ->assertInertia(fn ($page) => $page->where('can.delete', false)->where('checklist.in_use', true));
    $this->actingInCompany($superAdmin, $this->company)->delete(route('quality.checklists.destroy', $this->checklist))->assertSessionHasErrors('checklist');
    expect(QualityChecklist::query()->withoutGlobalScopes()->whereKey($this->checklist->id)->exists())->toBeTrue();
});
