<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Approval\ApprovalMode;
use App\Enums\Approval\ApproverType;
use App\Enums\ProjectRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApprovalWorkflowRequest;
use App\Models\Approval\ApprovalStep;
use App\Models\Approval\ApprovalWorkflow;
use App\Models\Core\Role;
use App\Services\Approval\ApprovalWorkflowService;
use App\Services\Core\CompanyDirectory;
use App\Support\Approval\ApprovalDocumentTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ApprovalWorkflowController extends Controller
{
    public function __construct(
        private readonly ApprovalWorkflowService $workflows,
        private readonly CompanyDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ApprovalWorkflow::class);

        $existing = ApprovalWorkflow::query()
            ->whereNull('project_id')
            ->with(['steps.user:id,name', 'steps.role:id,name'])
            ->get()
            ->keyBy('document_type');

        $groups = [];
        foreach (ApprovalDocumentTypes::all() as $type => $meta) {
            $workflow = $existing->get($type);
            $groups[$meta['group']][] = [
                'type' => $type,
                'label' => $meta['label'],
                'is_active' => $workflow?->is_active ?? false,
                'configured' => $workflow !== null && $workflow->steps->isNotEmpty(),
                'steps_count' => $workflow?->steps->count() ?? 0,
                'summary' => $workflow?->steps->map(fn (ApprovalStep $step) => $this->stepLabel($step))->all() ?? [],
            ];
        }

        return Inertia::render('Admin/Workflows/Index', [
            'groups' => collect($groups)->map(fn ($processes, $group) => [
                'group' => $group,
                'processes' => $processes,
            ])->values()->all(),
            'can' => [
                'manage' => $request->user()->can('create', ApprovalWorkflow::class),
            ],
        ]);
    }

    public function edit(Request $request, string $type): Response
    {
        $this->assertType($type);
        $workflow = $this->companyWide($type);
        if ($workflow) {
            Gate::authorize('view', $workflow);
        } else {
            Gate::authorize('viewAny', ApprovalWorkflow::class);
        }

        $steps = $workflow?->steps->map(fn (ApprovalStep $step) => [
            'name' => $step->name,
            'approver_type' => $step->approver_type->value,
            'user_id' => $step->user_id,
            'role_id' => $step->role_id,
            'project_role' => $step->project_role,
            'mode' => $step->mode->value,
        ])->values()->all() ?? [];

        return Inertia::render('Admin/Workflows/Edit', [
            'type' => $type,
            'label' => ApprovalDocumentTypes::label($type),
            'group' => ApprovalDocumentTypes::group($type),
            'workflow' => [
                'name' => $workflow?->name ?? ApprovalDocumentTypes::label($type).' approval',
                'is_active' => $workflow?->is_active ?? true,
                'steps' => $steps !== [] ? $steps : [$this->blankStep()],
            ],
            'users' => $this->directory->membersQuery()->get(['id', 'name', 'email'])
                ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name, 'description' => $u->email])
                ->all(),
            'roles' => Role::query()->forCurrentCompany()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Role $role) => ['value' => $role->id, 'label' => $role->name])
                ->all(),
            'approverTypes' => ApproverType::options(),
            'projectRoles' => ProjectRole::options(),
            'modes' => ApprovalMode::options(),
            'can' => [
                'manage' => $workflow
                    ? $request->user()->can('update', $workflow)
                    : $request->user()->can('create', ApprovalWorkflow::class),
            ],
        ]);
    }

    public function update(ApprovalWorkflowRequest $request, string $type): RedirectResponse
    {
        $this->assertType($type);
        $this->workflows->syncCompanyWide($type, $request->validated());

        return redirect()
            ->route('admin.workflows.index')
            ->with('success', ApprovalDocumentTypes::label($type).' approval steps saved. New submissions use this chain; documents already in approval keep their original steps.');
    }

    /**
     * @return array<string, mixed>
     */
    private function blankStep(): array
    {
        return [
            'name' => '',
            'approver_type' => ApproverType::User->value,
            'user_id' => null,
            'role_id' => null,
            'project_role' => null,
            'mode' => ApprovalMode::Any->value,
        ];
    }

    private function companyWide(string $type): ?ApprovalWorkflow
    {
        return ApprovalWorkflow::query()
            ->where('document_type', $type)
            ->whereNull('project_id')
            ->with(['steps.user:id,name,email', 'steps.role:id,name'])
            ->first();
    }

    private function assertType(string $type): void
    {
        if (! ApprovalDocumentTypes::has($type)) {
            throw new NotFoundHttpException;
        }
    }

    private function stepLabel(ApprovalStep $step): string
    {
        return match ($step->approver_type) {
            ApproverType::User => $step->user?->name ?? 'Specific user',
            ApproverType::Role => $step->role?->name ?? 'Company role',
            ApproverType::ProjectRole => ProjectRole::tryFrom((string) $step->project_role)?->label() ?? 'Project role',
        };
    }
}
