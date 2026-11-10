<?php

namespace App\Services\Projects;

use App\Enums\Approval\ApprovalMode;
use App\Enums\Approval\ApproverType;
use App\Enums\IndianState;
use App\Enums\ProjectRole;
use App\Models\Approval\ApprovalWorkflow;
use App\Models\Core\Company;
use App\Models\Core\CompanyUser;
use App\Models\Projects\Project;
use App\Models\Projects\ProjectSetting;
use App\Models\Projects\Site;
use App\Models\User;
use App\Services\Core\CompanyProvisioner;
use App\Services\Core\CompanyService;
use App\Support\Permissions\DefaultRoles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Idempotent bootstrap for the Bihar (BHR_60TPH) project team, settings and approval workflows.
 */
class BiharWorkflowBootstrapper
{
    public const PROJECT_CODE = 'BHR_60TPH';

    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly CompanyProvisioner $provisioner,
        private readonly CompanyService $companies,
        private readonly ProjectService $projects,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $company = Company::query()
            ->where('code', 'TIWANA')
            ->orWhere('name', 'like', '%Tiwana%')
            ->orderByRaw("CASE WHEN code = 'TIWANA' THEN 0 ELSE 1 END")
            ->firstOrFail();

        return $this->tenancy->runAs($company, fn () => $this->bootstrap($company));
    }

    /**
     * @return array<string, mixed>
     */
    private function bootstrap(Company $company): array
    {
        $this->provisioner->provision($company);
        $this->provisioner->grantMissingSystemRoleDefaults($company);

        $users = $this->ensureUsers($company);

        $project = Project::query()->where('code', self::PROJECT_CODE)->first();
        if ($project === null) {
            $project = $this->projects->create([
                'name' => 'Bihar',
                'code' => self::PROJECT_CODE,
                'city' => 'Bihar',
                'address' => 'Bihar',
                'project_type' => 'industrial',
                'state_code' => IndianState::Bihar->value,
                'project_manager_id' => $users['sumit']->id,
            ]);
        } elseif ((int) $project->project_manager_id !== (int) $users['sumit']->id) {
            $this->projects->update($project, ['project_manager_id' => $users['sumit']->id]);
            $project->refresh();
        }

        if (! $project->sites()->where('name', 'Bihar')->exists()) {
            $this->projects->createSite($project, ['name' => 'Bihar', 'is_active' => true]);
        }

        $this->assignProjectTeam($project, $users);
        $this->syncSettings($project);
        $this->syncWorkflows($project, $users);

        return [
            'company_id' => $company->id,
            'project_id' => $project->id,
            'project_code' => $project->code,
            'users' => count($this->userDefinitions()),
        ];
    }

    /**
     * @return array<string, User>
     */
    private function ensureUsers(Company $company): array
    {
        $resolved = [];

        foreach ($this->userDefinitions() as $key => $definition) {
            $user = User::query()->where('email', $definition['email'])->first();

            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'name' => $definition['name'],
                    'email' => $definition['email'],
                    'mobile' => $definition['mobile'],
                    'password' => Str::password(32),
                    'is_active' => true,
                    'email_verified_at' => $definition['verified'] ? now() : null,
                ])->save();
            } else {
                $user->forceFill([
                    'name' => $definition['name'],
                    'mobile' => $definition['mobile'],
                    'is_active' => true,
                ]);
                if ($definition['verified'] && $user->email_verified_at === null && ! $user->isPlaceholderEmail()) {
                    $user->email_verified_at = now();
                }
                $user->save();
            }

            $this->companies->addMember($company, $user, [$definition['role']]);

            CompanyUser::query()
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)
                ->update(['user_type' => $definition['user_type'], 'is_active' => true]);

            $resolved[$key] = $user->fresh();
        }

        return $resolved;
    }

    /**
     * @param  array<string, User>  $users
     */
    private function assignProjectTeam(Project $project, array $users): void
    {
        $map = [
            'bhaveeth' => ProjectRole::Billing,
            'maninder' => ProjectRole::Viewer,
            'kartik' => ProjectRole::Engineer,
            'aman' => ProjectRole::Engineer,
            'sumit' => ProjectRole::Manager,
            'satnam' => ProjectRole::Viewer,
            'khushpreet' => ProjectRole::Accounts,
            'jaipreet' => ProjectRole::Purchase,
            'savroop' => ProjectRole::Store,
        ];

        foreach ($map as $key => $role) {
            $this->projects->assignMember($project, $users[$key]->id, $role);
        }
    }

    private function syncSettings(Project $project): void
    {
        $project->settings()->updateOrCreate(
            ['key' => 'workflow_categories'],
            ['value' => [
                'Civil — Execution',
                'Civil — Supply',
                'Equipment — Supply',
                'Gate Pass',
            ]]
        );

        $project->settings()->updateOrCreate(
            ['key' => 'shared_contacts'],
            ['value' => [
                ['label' => 'Kartik/Aman unverified', 'mobile' => '7009034629'],
            ]]
        );

        $project->settings()->updateOrCreate(
            ['key' => 'workflow_playbooks'],
            ['value' => $this->workflowPlaybooks()]
        );
    }

    /**
     * @param  array<string, User>  $users
     */
    private function syncWorkflows(Project $project, array $users): void
    {
        $userStep = fn (User $user, string $name) => [
            'name' => $name,
            'approver_type' => ApproverType::User,
            'user_id' => $user->id,
            'mode' => ApprovalMode::Any,
        ];

        $projectRoleStep = fn (ProjectRole $role, string $name) => [
            'name' => $name,
            'approver_type' => ApproverType::ProjectRole,
            'project_role' => $role->value,
            'mode' => ApprovalMode::Any,
        ];

        $definitions = [
            'boq' => ['Bihar BOQ approval', [
                $userStep($users['maninder'], 'Civil Lead review'),
                $userStep($users['satnam'], 'Management approval'),
            ]],
            'work_order' => ['Bihar work order approval', [
                $userStep($users['sumit'], 'Project Manager'),
                $userStep($users['satnam'], 'Management approval'),
            ]],
            'subcontractor_bill' => ['Bihar subcontractor bill approval', [
                $userStep($users['maninder'], 'Civil Lead'),
                $userStep($users['sumit'], 'Project Manager'),
                $userStep($users['satnam'], 'Management approval'),
            ]],
            'material_request' => ['Bihar material request approval', [
                $userStep($users['sumit'], 'Project Manager'),
            ]],
            'purchase_order' => ['Bihar purchase order approval', [
                $userStep($users['sumit'], 'PM review (Jaipreet issues PO; see playbook note)'),
            ]],
            'vendor_bill' => ['Bihar vendor bill approval', [
                $userStep($users['sumit'], 'Project Manager'),
                $userStep($users['satnam'], 'Management approval'),
            ]],
            'grn' => ['Bihar GRN approval', [
                $projectRoleStep(ProjectRole::Store, 'Store (Savroop)'),
            ]],
            'gate_pass' => ['Bihar gate pass approval', [
                $userStep($users['savroop'], 'Store review'),
                $userStep($users['sumit'], 'Project Manager approval'),
            ]],
        ];

        DB::transaction(function () use ($project, $definitions) {
            foreach ($definitions as $documentType => [$name, $steps]) {
                $workflow = ApprovalWorkflow::query()->firstOrNew([
                    'document_type' => $documentType,
                    'project_id' => $project->id,
                ]);
                $workflow->fill(['name' => $name, 'is_active' => true])->save();
                $workflow->steps()->delete();

                foreach (array_values($steps) as $index => $step) {
                    $workflow->steps()->create($step + ['level' => $index + 1]);
                }
            }
        });
    }

    /**
     * @return array<string, array{name: string, email: string, role: string, user_type: string, mobile: ?string, verified: bool}>
     */
    private function userDefinitions(): array
    {
        return [
            'bhaveeth' => [
                'name' => 'Bhaveeth Singh',
                'email' => 'civil.drawings@tiwana.in',
                'role' => DefaultRoles::BOQ_PREP,
                'user_type' => 'head_office',
                'mobile' => '8968033360',
                'verified' => true,
            ],
            'maninder' => [
                'name' => 'Maninder Singh',
                'email' => 'civil@tiwana.in',
                'role' => DefaultRoles::CIVIL_LEAD,
                'user_type' => 'head_office',
                'mobile' => '9872121360',
                'verified' => true,
            ],
            'kartik' => [
                'name' => 'Kartik',
                'email' => 'kartik@users.tiwana.test',
                'role' => DefaultRoles::SITE_ENGINEER,
                'user_type' => 'site',
                'mobile' => null,
                'verified' => false,
            ],
            'aman' => [
                'name' => 'Aman',
                'email' => 'aman@users.tiwana.test',
                'role' => DefaultRoles::SITE_ENGINEER,
                'user_type' => 'site',
                'mobile' => null,
                'verified' => false,
            ],
            'sumit' => [
                'name' => 'Sumit',
                'email' => 'unithead.bihar@tiwana.in',
                'role' => DefaultRoles::PROJECT_MANAGER,
                'user_type' => 'site',
                'mobile' => '9915638360',
                'verified' => true,
            ],
            'satnam' => [
                'name' => 'Satnam Singh',
                'email' => 'satnam.singh26360@gmail.com',
                'role' => DefaultRoles::MANAGEMENT,
                'user_type' => 'head_office',
                'mobile' => '9878626360',
                'verified' => true,
            ],
            'khushpreet' => [
                'name' => 'Khushpreet Singh',
                'email' => 'tiwanafeed9@gmail.com',
                'role' => DefaultRoles::ACCOUNTANT,
                'user_type' => 'head_office',
                'mobile' => '9041045360',
                'verified' => true,
            ],
            'jaipreet' => [
                'name' => 'Jaipreet',
                'email' => 'store@tiwana.in',
                'role' => DefaultRoles::PURCHASE_MANAGER,
                'user_type' => 'head_office',
                'mobile' => '9872598360',
                'verified' => true,
            ],
            'savroop' => [
                'name' => 'Savroop',
                'email' => 'savroop@users.tiwana.test',
                'role' => DefaultRoles::STORE_INCHARGE,
                'user_type' => 'site',
                'mobile' => null,
                'verified' => false,
            ],
        ];
    }

    /**
     * Metadata for UI playbooks — not a second approval engine.
     *
     * @return array<string, mixed>
     */
    private function workflowPlaybooks(): array
    {
        return [
            'civil_execution' => [
                'label' => 'Civil — Execution',
                'notes' => 'BOQ and work-order flows use engine workflows on this project.',
                'steps' => [
                    ['key' => 'boq_prep', 'role' => 'BOQ Prep', 'user' => 'bhaveeth', 'action' => 'Prepare BOQ'],
                    ['key' => 'civil_lead', 'role' => 'Civil Lead', 'user' => 'maninder', 'action' => 'Review'],
                    ['key' => 'management', 'role' => 'Management', 'user' => 'satnam', 'action' => 'Approve'],
                ],
            ],
            'civil_supply' => [
                'label' => 'Civil — Supply',
                'notes' => 'ERP requires approved GRN lines before vendor bills; PDFs may show invoice before GRN.',
                'steps' => [
                    ['key' => 'material_request', 'role' => 'Site Engineer', 'user' => 'kartik|aman', 'action' => 'Raise PR'],
                    ['key' => 'pm', 'role' => 'Project Manager', 'user' => 'sumit', 'action' => 'Approve PR'],
                    ['key' => 'purchase', 'role' => 'Purchase Manager', 'user' => 'jaipreet', 'action' => 'Issue PO'],
                    ['key' => 'pm_po', 'role' => 'Project Manager', 'user' => 'sumit', 'action' => 'Approve PO (project workflow)'],
                    ['key' => 'store_grn', 'role' => 'Store Incharge', 'user' => 'savroop', 'action' => 'GRN approve'],
                    ['key' => 'vendor_bill', 'role' => 'Project Manager → Management', 'user' => 'sumit|satnam', 'action' => 'Vendor bill'],
                ],
            ],
            'equipment_supply' => [
                'label' => 'Equipment — Supply',
                'notes' => 'Site engineers may create GRNs; approval stays with store project role (Savroop).',
                'steps' => [
                    ['key' => 'pr', 'role' => 'Site Engineer', 'user' => 'kartik|aman', 'action' => 'Equipment PR'],
                    ['key' => 'pm', 'role' => 'Project Manager', 'user' => 'sumit', 'action' => 'Approve PR'],
                    ['key' => 'po', 'role' => 'Purchase Manager', 'user' => 'jaipreet', 'action' => 'PO issue'],
                    ['key' => 'grn', 'role' => 'Store', 'user' => 'savroop', 'action' => 'GRN approve'],
                ],
            ],
            'gate_pass' => [
                'label' => 'Gate Pass',
                'steps' => [
                    ['key' => 'create', 'role' => 'Site Engineer', 'user' => 'kartik|aman', 'action' => 'Create / submit'],
                    ['key' => 'store', 'role' => 'Store Incharge', 'user' => 'savroop', 'action' => 'Review L1'],
                    ['key' => 'pm', 'role' => 'Project Manager', 'user' => 'sumit', 'action' => 'Approve L2'],
                ],
            ],
        ];
    }
}
