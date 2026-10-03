<?php

namespace Tests\Concerns;

use App\Enums\ProjectRole;
use App\Models\Boq\Boq;
use App\Models\Boq\RateAnalysis;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Material;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Services\Approval\ApprovalService;
use App\Services\Boq\BoqService;
use App\Services\Boq\RateAnalysisService;
use App\Services\Projects\ProjectService;
use App\Support\Permissions\DefaultRoles;

/**
 * Phase 2 fixtures: a provisioned company, one user per relevant default role and a project whose
 * manager is $this->pm, with the billing and site engineers on the team.
 */
trait BuildsProjectPlanningData
{
    public function setUpProjectTeam(): void
    {
        $this->company = $this->createCompany();
        $this->admin = $this->createMember($this->company, DefaultRoles::COMPANY_ADMIN);
        $this->pm = $this->createMember($this->company, DefaultRoles::PROJECT_MANAGER);
        $this->director = $this->createMember($this->company, DefaultRoles::DIRECTOR);
        $this->billing = $this->createMember($this->company, DefaultRoles::BILLING_ENGINEER);
        $this->engineer = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
        $this->project = $this->makeTeamProject('Tower A');
    }

    public function makeTeamProject(string $name): Project
    {
        return $this->inCompany($this->company, function () use ($name) {
            $projects = app(ProjectService::class);
            $project = $projects->create(['name' => $name, 'project_manager_id' => $this->pm->id]);
            $projects->assignMember($project, $this->billing->id, ProjectRole::Billing);
            $projects->assignMember($project, $this->engineer->id, ProjectRole::Engineer);

            return $project;
        });
    }

    public function unitId(string $symbol = 'Cum'): int
    {
        return $this->inCompany($this->company, fn () => (int) Unit::query()->where('symbol', $symbol)->value('id'));
    }

    /**
     * Line 1: 12.5 × (4500.5 + 1200.25 + 300) = 75,009.38 cost; 15 % margin → client 86,260.78.
     * Line 2: 3 × (3.335 + 3.335) = 20.01 cost; client rate override 10 → client 30.00.
     *
     * @return list<array<string, string>>
     */
    public function sampleBoqLines(): array
    {
        return [
            ['item_code' => 'A.1', 'name' => 'Excavation', 'quantity' => '12.5', 'material_rate' => '4500.5', 'labour_rate' => '1200.25', 'equipment_rate' => '300', 'subcontract_rate' => '0', 'margin_percent' => '15'],
            ['item_code' => 'A.2', 'name' => 'PCC', 'quantity' => '3', 'material_rate' => '3.335', 'labour_rate' => '3.335', 'margin_percent' => '0', 'client_rate' => '10'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $lines
     */
    public function makeBoq(?array $lines = null, ?Project $project = null, string $title = 'Civil works'): Boq
    {
        $project ??= $this->project;
        $unitId = $this->unitId();

        return $this->inCompany($this->company, function () use ($lines, $project, $title, $unitId) {
            $service = app(BoqService::class);
            $boq = $service->create($project, ['title' => $title]);
            $section = $service->saveSection($boq, ['code' => 'A', 'name' => 'Earthwork']);
            $rows = array_map(fn (array $line) => $line + ['boq_section_id' => $section->id, 'unit_id' => $unitId], $lines ?? $this->sampleBoqLines());
            $service->syncItems($boq, $rows, [], true);

            return $boq->fresh();
        });
    }

    /**
     * Submit (billing engineer) → level 1 project manager → level 2 director.
     */
    public function approveBoq(Boq $boq): Boq
    {
        return $this->inCompany($this->company, function () use ($boq) {
            app(BoqService::class)->submit($boq, $this->billing);
            $approvals = app(ApprovalService::class);
            $request = $approvals->approve($boq->fresh()->pendingApprovalRequest(), $this->pm);
            $approvals->approve($request, $this->director);

            return $boq->fresh();
        });
    }

    /**
     * 10 Cum output: material 4 × 5000 (+2.5 % wastage) = 20,500.00, labour 3 × 850 = 2,550.00,
     * equipment 1 × 1200 = 1,200.00, other 1 × 333.333 = 333.33; overhead 10 %, profit 7.5 %.
     */
    public function makeApprovedRateAnalysis(?Project $project = null): RateAnalysis
    {
        $project ??= $this->project;
        $data = $this->sampleRateAnalysisData();

        return $this->inCompany($this->company, function () use ($project, $data) {
            $service = app(RateAnalysisService::class);

            return $service->approve($service->save($project, $data), $this->billing);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function sampleRateAnalysisData(): array
    {
        $cum = $this->unitId();
        $bag = $this->unitId('Bag');
        [$material, $trade, $equipment] = $this->inCompany($this->company, fn () => [
            Material::query()->firstOrCreate(['code' => 'CEM53'], ['name' => 'Cement OPC 53', 'unit_id' => $bag])->id,
            LabourTrade::query()->where('name', 'Mason')->value('id'),
            EquipmentType::query()->where('name', 'Concrete Mixer')->value('id'),
        ]);

        return [
            'name' => 'M20 concrete',
            'unit_id' => $cum,
            'output_quantity' => '10',
            'overhead_percent' => '10',
            'profit_percent' => '7.5',
            'items' => [
                ['resource_type' => 'material', 'material_id' => $material, 'description' => 'Cement', 'quantity' => '4', 'wastage_percent' => '2.5', 'rate' => '5000'],
                ['resource_type' => 'labour', 'labour_trade_id' => $trade, 'description' => 'Mason', 'quantity' => '3', 'rate' => '850'],
                ['resource_type' => 'equipment', 'equipment_type_id' => $equipment, 'description' => 'Mixer', 'quantity' => '1', 'rate' => '1200'],
                ['resource_type' => 'other', 'description' => 'Curing', 'quantity' => '1', 'rate' => '333.333'],
            ],
        ];
    }
}
