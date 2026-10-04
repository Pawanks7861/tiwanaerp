<?php

use App\Models\Crm\Lead;
use App\Services\Quality\NcrService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsQualityData;

uses(BuildsQualityData::class);

beforeEach(function () {
    $this->setUpQuality();
});

test('quality counts match the inspections and NCRs that were recorded', function () {
    $this->completedInspection();
    $failed = $this->failedInspection();
    $this->completedInspection([], 'conditional', 'Cure for another day');

    $open = $this->raiseNcr(['severity' => 'major'], $failed);
    $closed = $this->raiseNcr(['severity' => 'minor', 'issue' => 'Honeycomb at the lift wall']);
    $this->inCompany($this->company, function () use ($closed) {
        $ncrs = app(NcrService::class);
        $ncrs->start($closed);
        $ncrs->resolve($closed->fresh(), ['root_cause' => 'Poor vibration', 'corrective_action' => 'Chip and repair'], $this->engineer);
        $ncrs->verify($closed->fresh(), 'Repair holds', $this->qe);
        $ncrs->close($closed->fresh(), $this->qe);
    });

    $this->actingInCompany($this->director, $this->company)
        ->get(route('reports.project', [$this->project, 'quality-summary']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.totals.requested', 3)
            ->where('result.totals.completed', 3)
            ->where('result.totals.passed', 1)
            ->where('result.totals.failed', 1)
            ->where('result.totals.conditional', 1)
            ->where('result.totals.pass_rate', '33.33')
            ->where('result.totals.ncr_raised', 2)
            ->where('result.totals.ncr_closed', 1)
            ->where('result.totals.ncr_open', 1)
            ->where('result.totals.ncr_major', 1)
            ->where('result.totals.ncr_minor', 0));

    expect($open->fresh()->status->value)->toBe('open');
});

test('the CRM funnel counts stored stages, sources, assignees and estimated value only', function () {
    $this->inCompany($this->company, function () {
        $make = function (string $number, string $status, string $source, ?int $assignee, string $value) {
            $lead = new Lead;
            $lead->forceFill([
                'lead_number' => $number,
                'name' => $number,
                'source' => $source,
                'status' => $status,
                'assigned_to' => $assignee,
                'estimated_value' => $value,
                'lost_reason' => $status === 'lost' ? 'Price' : null,
            ])->save();
        };
        $make('LD-1', 'new', 'website', $this->pm->id, '100000');
        $make('LD-2', 'qualified', 'website', $this->pm->id, '250000');
        $make('LD-3', 'quoted', 'referral', null, '400000');
        $make('LD-4', 'won', 'referral', $this->director->id, '800000');
        $make('LD-5', 'lost', 'website', $this->pm->id, '50000');
    });

    $this->actingInCompany($this->director, $this->company)
        ->get(route('reports.show', 'crm-funnel').'?view=source')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.totals.leads', 5)
            ->where('result.totals.new_leads', 1)
            ->where('result.totals.qualified', 1)
            ->where('result.totals.quoted', 1)
            ->where('result.totals.won', 1)
            ->where('result.totals.lost', 1)
            ->where('result.totals.pipeline_value', '1600000.00')
            ->where('result.totals.quotes', 0)
            ->where('result.totals.converted', 0)
            ->where('result.rows', fn ($rows) => collect($rows)->firstWhere('label', 'Website')['leads'] === 3
                && collect($rows)->firstWhere('label', 'Referral')['won'] === 1));

    $this->actingInCompany($this->director, $this->company)
        ->get(route('reports.show', 'crm-funnel').'?view=assignee&assignee_id='.$this->pm->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.totals.leads', 3)
            ->where('result.totals.pipeline_value', '400000.00'));
});
