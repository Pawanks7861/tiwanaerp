<?php

namespace App\Services\Tutorial;

use App\Models\Projects\Project;
use App\Models\User;
use App\Support\Procurement\ProcurementSettings;

class TutorialStepRegistry
{
    /**
     * Full catalog. Eligibility is resolved per user via permissions / project availability.
     *
     * @return list<array{
     *   key: string,
     *   title: string,
     *   body: string,
     *   target: ?string,
     *   route: ?string,
     *   route_params?: array<string, mixed>,
     *   permission: string|list<string>|null,
     *   requires_project?: bool,
     *   placement?: string
     * }>
     */
    public function catalog(?int $projectId = null): array
    {
        $projectParams = $projectId ? ['project' => $projectId] : [];

        return [
            [
                'key' => 'welcome',
                'title' => 'Welcome to Tiwana ERP',
                'body' => "Welcome to Tiwana ERP! Let's take a quick tour to understand how to use your workspace.\n\nThis short guide highlights the main features available to you. You can pause and come back later — progress is saved until every required step is finished.",
                'target' => null,
                'route' => null,
                'permission' => null,
                'placement' => 'center',
            ],
            [
                'key' => 'dashboard',
                'title' => 'Dashboard',
                'body' => "Your Dashboard is the home overview of Tiwana ERP.\n\nHere you can review key indicators, project status, notifications and pending work that you are allowed to see.",
                'target' => '[data-tour="dashboard-main"]',
                'route' => 'dashboard',
                'permission' => 'dashboard.view',
                'placement' => 'bottom',
            ],
            [
                'key' => 'sidebar',
                'title' => 'Sidebar navigation',
                'body' => "Use the left sidebar to move between modules.\n\n• Expand section headings to find related menus\n• The selected item is highlighted\n• The sidebar scrolls so the active module stays in view",
                'target' => '[data-tour="sidebar"]',
                'route' => null,
                'permission' => null,
                'placement' => 'right',
            ],
            [
                'key' => 'projects',
                'title' => 'Projects',
                'body' => "Projects are the workspace for drawings, BOQ, procurement, inventory and site work.\n\nOpen a project from this list (or the project switcher) to see its details and project-specific modules.",
                'target' => '[data-tour="nav-projects"]',
                'route' => 'projects.index',
                'permission' => 'projects.view',
                'placement' => 'right',
            ],
            [
                'key' => 'project_tabs',
                'title' => 'Project module tabs',
                'body' => $projectId
                    ? "Inside a project, these horizontal tabs organize Drawing Management, BOQ, Procurement, Inventory, Finance, Documents and other modules you can access.\n\nThe active tab stays visible as you navigate."
                    : "When you open a project, horizontal tabs organize Drawing Management, BOQ, Procurement, Inventory, Finance, Documents and other modules you can access.\n\nOpen any project later to explore those tabs — you can continue this tour without creating data.",
                'target' => $projectId ? '[data-tour="project-tabs"]' : '[data-tour="nav-projects"]',
                'route' => $projectId ? 'projects.show' : 'projects.index',
                'route_params' => $projectId ? $projectParams : [],
                'permission' => 'projects.view',
                'requires_project' => false,
                'placement' => $projectId ? 'bottom' : 'right',
            ],
            [
                'key' => 'drawings',
                'title' => 'Drawing Management',
                'body' => "Drawing Management is where you upload drawings, manage revisions, track approval status and open the drawing viewer.\n\nApproved drawings are required before creating a new BOQ.\n\nNote: Spreadsheet import is not used for drawings — register them by uploading files.",
                'target' => $projectId ? '[data-tour="project-tabs"]' : '[data-tour="nav-projects"]',
                'route' => $projectId ? 'projects.drawings.index' : 'projects.index',
                'route_params' => $projectId ? $projectParams : [],
                'permission' => 'drawings.view',
                'placement' => $projectId ? 'bottom' : 'right',
            ],
            [
                'key' => 'boq',
                'title' => 'BOQ Management',
                'body' => "BOQ Management lets you create bills of quantities, add items and review totals.\n\nNew BOQs can only be created from currently approved drawing revisions belonging to the same project.\n\nOpen BOQ Management from the project tabs when you have an approved drawing.",
                'target' => $projectId ? '[data-tour="project-tabs"]' : '[data-tour="nav-projects"]',
                'route' => $projectId ? 'projects.boqs.index' : 'projects.index',
                'route_params' => $projectId ? $projectParams : [],
                'permission' => 'boq.view',
                'placement' => $projectId ? 'bottom' : 'right',
            ],
            [
                'key' => 'procurement',
                'title' => 'Procurement',
                'body' => "Procurement covers Purchase Requests, Purchase Orders, approvals and PO status tracking.\n\nWork follows the approval workflow — drafts must be submitted and approved before they become committed orders.",
                'target' => $projectId ? '[data-tour="project-tabs"]' : '[data-tour="nav-projects"]',
                'route' => $projectId ? 'projects.purchase-orders.index' : 'projects.index',
                'route_params' => $projectId ? $projectParams : [],
                'permission' => ['purchase.view', 'material_requests.view', 'grn.view'],
                'placement' => $projectId ? 'bottom' : 'right',
            ],
            [
                'key' => 'inventory',
                'title' => 'Inventory',
                'body' => "Inventory covers the material master, central store balances, goods receipts, material issues and stock movements.\n\nUse Inventory and GRN screens according to your permissions — the tour never bypasses access rules.",
                'target' => $projectId ? '[data-tour="project-tabs"]' : '[data-tour="nav-projects"]',
                'route' => $projectId ? 'projects.inventory.index' : 'projects.index',
                'route_params' => $projectId ? $projectParams : [],
                'permission' => 'inventory.view',
                'placement' => $projectId ? 'bottom' : 'right',
            ],
            [
                'key' => 'reports',
                'title' => 'Reports',
                'body' => "Reports let you filter and review authorized project and company information.\n\nOpen Reports from the sidebar (or from a project) and use filters to focus on the data you are allowed to see.",
                'target' => '[data-tour="nav-reports"]',
                'route' => 'reports.index',
                'permission' => 'reports.view',
                'placement' => 'right',
            ],
            [
                'key' => 'smart_assistant',
                'title' => 'Smart Assistant',
                'body' => "You can use the Smart Assistant to understand ERP workflows or check permitted business information.\n\nTry asking:\n• How do I create a BOQ?\n• What is my total PO value this month?\n• How many drawings are pending approval?\n• What is the available material stock?",
                'target' => '[data-tour="smart-assistant"]',
                'route' => null,
                'permission' => 'ai.use',
                'placement' => 'left',
            ],
            [
                'key' => 'complete',
                'title' => 'Tutorial complete',
                'body' => "Congratulations! You've completed the Tiwana ERP tutorial. You're ready to start working.",
                'target' => null,
                'route' => null,
                'permission' => null,
                'placement' => 'center',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stepsFor(User $user, ?int $projectId = null): array
    {
        $projectId ??= $this->sampleProjectId($user);
        $steps = [];

        foreach ($this->catalog($projectId) as $step) {
            if (! $this->userMaySee($user, $step)) {
                continue;
            }

            // Prefer a route the user can actually open for procurement.
            if ($step['key'] === 'procurement' && $projectId) {
                $step = $this->resolveProcurementRoute($user, $step, $projectId);
            }

            $steps[] = [
                'key' => $step['key'],
                'title' => $step['title'],
                'body' => $step['body'],
                'target' => $step['target'],
                'route' => $step['route'],
                'route_params' => $step['route_params'] ?? [],
                'placement' => $step['placement'] ?? 'bottom',
            ];
        }

        return $steps;
    }

    /**
     * @return list<string>
     */
    public function requiredStepKeys(User $user, ?int $projectId = null): array
    {
        return array_values(array_map(
            fn (array $step) => $step['key'],
            array_filter(
                $this->stepsFor($user, $projectId),
                fn (array $step) => $step['key'] !== 'complete',
            ),
        ));
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function userMaySee(User $user, array $step): bool
    {
        $permission = $step['permission'] ?? null;
        if ($permission === null) {
            return true;
        }

        if (is_array($permission)) {
            foreach ($permission as $name) {
                if ($user->can($name)) {
                    return true;
                }
            }

            return false;
        }

        return $user->can($permission);
    }

    public function sampleProjectId(User $user): ?int
    {
        if (! $user->can('projects.view')) {
            return null;
        }

        return Project::query()->visibleTo($user)->orderBy('id')->value('id');
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function resolveProcurementRoute(User $user, array $step, int $projectId): array
    {
        $candidates = [
            ['material_requests.view', 'projects.material-requests.index'],
            ['purchase.view', 'projects.purchase-orders.index'],
            ['grn.view', 'projects.grns.index'],
            ...(ProcurementSettings::rfqEnabled() ? [['rfq.view', 'projects.rfqs.index']] : []),
        ];

        foreach ($candidates as [$perm, $route]) {
            if ($user->can($perm)) {
                $step['route'] = $route;
                $step['route_params'] = ['project' => $projectId];

                return $step;
            }
        }

        $step['route'] = 'projects.index';
        $step['route_params'] = [];

        return $step;
    }
}
