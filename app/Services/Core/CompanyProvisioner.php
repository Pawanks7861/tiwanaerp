<?php

namespace App\Services\Core;

use App\Enums\Approval\ApprovalMode;
use App\Enums\Approval\ApproverType;
use App\Enums\CostHead;
use App\Enums\ProjectRole;
use App\Models\Approval\ApprovalWorkflow;
use App\Models\Core\Company;
use App\Models\Core\FinancialYear;
use App\Models\Core\Role;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\ExpenseCategory;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use App\Services\Boq\ConcreteMixProfileService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Projects\ProjectCostHeadService;
use App\Services\Scrap\ScrapMasterBootstrapper;
use App\Support\Math\Decimal;
use App\Support\Permissions\DefaultRoles;
use App\Support\Permissions\PermissionCatalog;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sets up a new company: default roles, starter masters, current financial year and default
 * approval workflows. Idempotent, so it can be re-run safely.
 */
class CompanyProvisioner
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly PermissionRegistrar $registrar,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function provision(Company $company): void
    {
        $this->ensurePermissions();

        $this->tenancy->runAs($company, function () use ($company) {
            $previousTeam = $this->registrar->getPermissionsTeamId();
            $this->registrar->setPermissionsTeamId($company->id);

            try {
                DB::transaction(function () use ($company) {
                    $roles = $this->seedRoles($company);
                    $this->grantMissingSystemRoleDefaults($company);
                    $this->seedMasters();
                    app(ProjectCostHeadService::class)->ensureDefaults($company);
                    app(ConcreteMixProfileService::class)->ensureDefaults($company);
                    app(ScrapMasterBootstrapper::class)->seedForCompany($company);
                    $this->seedFinancialYear($company);
                    $this->seedApprovalWorkflows($roles);
                });
            } finally {
                $this->registrar->setPermissionsTeamId($previousTeam);
            }
        });

        $this->registrar->forgetCachedPermissions();
    }

    /**
     * For every company: ensure catalogue permissions exist, then grant any missing
     * DefaultRoles intents to matching system roles (additive only — never strips customs).
     *
     * @return array{companies: int, roles_touched: int, permissions_granted: int}
     */
    public function refreshAllSystemRoleDefaults(): array
    {
        $this->ensurePermissions();

        $companies = 0;
        $rolesTouched = 0;
        $granted = 0;

        Company::query()->orderBy('id')->each(function (Company $company) use (&$companies, &$rolesTouched, &$granted) {
            $companies++;
            $this->tenancy->runAs($company, function () use ($company, &$rolesTouched, &$granted) {
                $previousTeam = $this->registrar->getPermissionsTeamId();
                $this->registrar->setPermissionsTeamId($company->id);
                try {
                    $result = $this->grantMissingSystemRoleDefaults($company);
                    $rolesTouched += $result['roles_touched'];
                    $granted += $result['permissions_granted'];
                } finally {
                    $this->registrar->setPermissionsTeamId($previousTeam);
                }
            });
        });

        $this->registrar->forgetCachedPermissions();

        return [
            'companies' => $companies,
            'roles_touched' => $rolesTouched,
            'permissions_granted' => $granted,
        ];
    }

    /**
     * Additive sync of DefaultRoles intents onto existing system roles for one company.
     * Custom (non-system) roles and unknown role names are untouched.
     * Company Admin remains fully expanded to `*`.
     *
     * @return array{roles_touched: int, permissions_granted: int, details: array<string, list<string>>}
     */
    public function grantMissingSystemRoleDefaults(Company $company): array
    {
        $touched = 0;
        $granted = 0;
        $details = [];

        foreach (DefaultRoles::definitions() as $name => $definition) {
            $role = Role::query()
                ->where('team_id', $company->id)
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->where('is_system', true)
                ->first();

            if (! $role) {
                continue;
            }

            $intended = PermissionCatalog::expand($definition['permissions']);

            if ($name === DefaultRoles::COMPANY_ADMIN) {
                // Keep Company Admin complete; full sync is still safe (catalogue is authoritative).
                $before = $role->permissions()->count();
                $role->syncPermissionNames($intended);
                $after = $role->permissions()->count();
                $added = max(0, $after - $before);
                if ($added > 0 || $after !== $before) {
                    $touched++;
                    $granted += $added;
                    $details[$name] = array_values(array_diff($intended, []));
                }

                continue;
            }

            $addedNames = $role->grantMissingPermissionNames($intended);
            if ($addedNames !== []) {
                $touched++;
                $granted += count($addedNames);
                $details[$name] = $addedNames;
            }
        }

        return ['roles_touched' => $touched, 'permissions_granted' => $granted, 'details' => $details];
    }

    public function ensurePermissions(): void
    {
        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $missing = array_diff(PermissionCatalog::all(), $existing);

        if ($missing !== []) {
            Permission::query()->insert(array_map(fn ($name) => [
                'name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
            ], array_values($missing)));
            $this->registrar->forgetCachedPermissions();
        }
    }

    /**
     * @return array<string, Role>
     */
    private function seedRoles(Company $company): array
    {
        $roles = [];
        foreach (DefaultRoles::definitions() as $name => $definition) {
            $role = Role::query()->firstOrCreate(
                ['team_id' => $company->id, 'name' => $name, 'guard_name' => 'web'],
                ['description' => $definition['description'], 'is_system' => true],
            );
            // Companies may customise default roles; only new roles and Company Admin are (re)synced.
            if ($role->wasRecentlyCreated || $name === DefaultRoles::COMPANY_ADMIN) {
                $role->syncPermissionNames(PermissionCatalog::expand($definition['permissions']));
            }
            $roles[$name] = $role;
        }

        return $roles;
    }

    private function seedMasters(): void
    {
        $units = [
            ['Number', 'Nos', 0], ['Kilogram', 'Kg', 3], ['Metric Tonne', 'MT', 3], ['Bag', 'Bag', 0],
            ['Cubic Metre', 'Cum', 3], ['Square Metre', 'Sqm', 3], ['Running Metre', 'Rmt', 3],
            ['Cubic Feet', 'Cft', 3], ['Square Feet', 'Sqft', 3], ['Litre', 'Ltr', 2], ['Hour', 'Hr', 2],
            ['Day', 'Day', 1], ['Lump Sum', 'LS', 0], ['Set', 'Set', 0],
        ];
        foreach ($units as [$name, $symbol, $decimals]) {
            Unit::query()->firstOrCreate(['symbol' => $symbol], ['name' => $name, 'decimal_places' => $decimals]);
        }

        foreach (['0', '5', '12', '18', '28'] as $rate) {
            $half = Decimal::of($rate)->dividedBy(2, Decimal::PERCENT_SCALE)->toString();
            TaxRate::query()->firstOrCreate(['name' => "GST {$rate}%"], [
                'rate' => $rate, 'cgst_rate' => $half, 'sgst_rate' => $half, 'igst_rate' => $rate, 'cess_rate' => '0',
            ]);
        }

        foreach (['Mason', 'Helper', 'Carpenter', 'Bar Bender', 'Electrician', 'Plumber', 'Painter', 'Welder', 'Supervisor'] as $trade) {
            LabourTrade::query()->firstOrCreate(['name' => $trade]);
        }

        foreach (['Excavator', 'Concrete Mixer', 'Vibrator', 'Crane', 'Transit Mixer', 'DG Set', 'Tipper'] as $type) {
            EquipmentType::query()->firstOrCreate(['name' => $type]);
        }

        $categories = [
            'Site Expense' => CostHead::Other, 'Transport' => CostHead::Other, 'Food' => CostHead::Other,
            'Fuel' => CostHead::Equipment, 'Labour' => CostHead::Labour, 'Material' => CostHead::Material,
            'Equipment' => CostHead::Equipment, 'Office' => CostHead::Overhead, 'Miscellaneous' => CostHead::Other,
        ];
        foreach ($categories as $name => $head) {
            ExpenseCategory::query()->firstOrCreate(['name' => $name], ['cost_head' => $head]);
        }
    }

    private function seedFinancialYear(Company $company): void
    {
        $label = $this->numbers->financialYearLabel($company, now());
        $startMonth = $company->fy_start_month ?: 4;
        $start = now()->month >= $startMonth
            ? now()->setDate(now()->year, $startMonth, 1)
            : now()->setDate(now()->year - 1, $startMonth, 1);

        FinancialYear::query()->firstOrCreate(['name' => $label], [
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addYear()->subDay()->toDateString(),
            'is_current' => true,
        ]);
    }

    /**
     * Default workflows from architecture J.1. "Project Manager" steps resolve to the document's
     * own project manager (project role), not every PM in the company.
     *
     * @param  array<string, Role>  $roles
     */
    private function seedApprovalWorkflows(array $roles): void
    {
        $pm = ['approver_type' => ApproverType::ProjectRole, 'project_role' => ProjectRole::Manager->value, 'name' => 'Project Manager'];
        $role = fn (string $name) => ['approver_type' => ApproverType::Role, 'role_id' => $roles[$name]->id, 'name' => $name];

        $workflows = [
            'material_request' => ['Material Request', [$pm, $role(DefaultRoles::PURCHASE_MANAGER)]],
            'purchase_order' => ['Purchase Order', [$pm, $role(DefaultRoles::DIRECTOR)]],
            'grn' => ['GRN', [$pm]],
            'material_issue' => ['Material Issue', [$pm]],
            'material_return' => ['Material Return', [$pm]],
            'dpr' => ['DPR', [$pm]],
            'client_invoice' => ['Client Bill', [$pm, $role(DefaultRoles::DIRECTOR)]],
            'work_order' => ['Work Order', [$pm, $role(DefaultRoles::DIRECTOR)]],
            'subcontractor_bill' => ['Subcontractor Bill', [$pm, $role(DefaultRoles::DIRECTOR)]],
            'expense' => ['Expense', [$pm, $role(DefaultRoles::ACCOUNTANT)]],
            'vendor_bill' => ['Vendor Bill', [$pm, $role(DefaultRoles::DIRECTOR)]],
            'retention_release' => ['Retention Release', [$pm, $role(DefaultRoles::DIRECTOR)]],
            'boq' => ['BOQ', [
                ['approver_type' => ApproverType::ProjectRole, 'project_role' => ProjectRole::Manager->value, 'name' => 'Technical Approval'],
                ['approver_type' => ApproverType::Role, 'role_id' => $roles[DefaultRoles::DIRECTOR]->id, 'name' => 'Commercial Approval'],
            ]],
            'gate_pass' => ['Gate Pass', [$pm]],
            'scrap_generation' => ['Scrap Generation', [$pm]],
            'scrap_adjustment' => ['Scrap Adjustment', [$pm]],
        ];

        foreach ($workflows as $type => [$name, $steps]) {
            if (ApprovalWorkflow::query()->where('document_type', $type)->whereNull('project_id')->exists()) {
                continue;
            }

            $workflow = ApprovalWorkflow::query()->create([
                'document_type' => $type,
                'name' => "{$name} approval",
                'is_active' => true,
            ]);

            foreach (array_values($steps) as $index => $step) {
                $workflow->steps()->create($step + ['level' => $index + 1, 'mode' => ApprovalMode::Any]);
            }
        }
    }
}
