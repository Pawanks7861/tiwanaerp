<?php

namespace App\Services\Ai\Tools;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Ai\Contracts\AiTool;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates PO grand_total from the purchase_orders header (never from items join).
 */
class GetPurchaseOrderSummaryTool implements AiTool
{
    public function __construct(private readonly CurrentCompany $company) {}

    public function name(): string
    {
        return 'getPurchaseOrderSummary';
    }

    public function description(): string
    {
        return 'Calculate authorized Purchase Order totals for a date range (default: current calendar month). Returns amount, count, currency, filters and status set. Uses document po_date and header grand_total. Excludes draft, rejected and cancelled by default.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'enum' => ['this_month', 'last_month', 'today', 'custom'],
                    'description' => 'Preset period. Default this_month.',
                ],
                'from' => ['type' => 'string', 'description' => 'YYYY-MM-DD when period=custom'],
                'to' => ['type' => 'string', 'description' => 'YYYY-MM-DD when period=custom'],
                'project_id' => ['type' => 'integer', 'description' => 'Limit to one project the user can access'],
                'vendor_id' => ['type' => 'integer', 'description' => 'Optional vendor filter'],
                'include_drafts' => ['type' => 'boolean', 'description' => 'If true and authorized, include draft/submitted. Default false.'],
                'pending_only' => ['type' => 'boolean', 'description' => 'If true, count only submitted POs awaiting approval.'],
                'group_by' => [
                    'type' => 'string',
                    'enum' => ['none', 'vendor', 'project'],
                    'description' => 'Optional breakdown. Default none.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        if (! $user->can('purchase.view')) {
            return false;
        }

        if (! empty($arguments['project_id'])) {
            $project = Project::query()->find((int) $arguments['project_id']);

            return $project !== null && $user->can('view', $project);
        }

        return true;
    }

    public function handle(User $user, array $arguments): array
    {
        if (! $this->authorize($user, $arguments)) {
            return ['error' => 'restricted', 'message' => 'You do not have permission to view purchase order values for this scope.'];
        }

        $tz = $this->company->get()?->timezone ?: config('app.timezone', 'Asia/Kolkata');
        [$from, $to, $label] = $this->period($arguments, $tz);

        $pendingOnly = (bool) ($arguments['pending_only'] ?? false);
        $statuses = $pendingOnly
            ? [PurchaseOrderStatus::Submitted->value]
            : $this->statuses((bool) ($arguments['include_drafts'] ?? false));
        $canFinancials = $user->can('dashboard.view_financials') || $user->can('reports.view_financial');

        $query = PurchaseOrder::query()
            ->whereIn('status', $statuses);

        if (! $pendingOnly) {
            $query->whereBetween('po_date', [$from->toDateString(), $to->toDateString()]);
        }

        if (! empty($arguments['project_id'])) {
            $query->where('project_id', (int) $arguments['project_id']);
        } else {
            $visible = Project::query()->visibleTo($user)->select('id');
            $query->whereIn('project_id', $visible);
        }

        if (! empty($arguments['vendor_id'])) {
            $query->where('vendor_id', (int) $arguments['vendor_id']);
        }

        $count = (clone $query)->count();
        $total = (string) ((clone $query)->sum('grand_total') ?? '0');
        $currency = $this->company->get()?->currency ?: 'INR';

        $breakdown = [];
        $groupBy = $arguments['group_by'] ?? 'none';
        if ($groupBy === 'vendor' && $canFinancials) {
            $rows = (clone $query)
                ->select('vendor_id', DB::raw('COUNT(*) as po_count'), DB::raw('SUM(grand_total) as total'))
                ->groupBy('vendor_id')
                ->orderByDesc('total')
                ->limit(10)
                ->get();
            $vendors = \App\Models\Masters\Vendor::query()
                ->whereIn('id', $rows->pluck('vendor_id')->filter())
                ->get(['id', 'code', 'name'])
                ->keyBy('id');
            $breakdown = $rows->map(fn ($row) => [
                'vendor_id' => $row->vendor_id,
                'vendor' => $vendors->get($row->vendor_id)?->name,
                'code' => $vendors->get($row->vendor_id)?->code,
                'po_count' => (int) $row->po_count,
                'total' => (string) $row->total,
            ])->all();
        }

        if ($groupBy === 'project' && $canFinancials) {
            $rows = (clone $query)
                ->select('project_id', DB::raw('COUNT(*) as po_count'), DB::raw('SUM(grand_total) as total'))
                ->groupBy('project_id')
                ->orderByDesc('total')
                ->limit(10)
                ->get();
            $projects = Project::query()
                ->whereIn('id', $rows->pluck('project_id')->filter())
                ->get(['id', 'code', 'name'])
                ->keyBy('id');
            $breakdown = $rows->map(fn ($row) => [
                'project_id' => $row->project_id,
                'project' => $projects->get($row->project_id)?->name,
                'code' => $projects->get($row->project_id)?->code,
                'po_count' => (int) $row->po_count,
                'total' => (string) $row->total,
            ])->all();
        }

        return [
            'period_label' => $pendingOnly ? 'Pending approval (all open submitted POs)' : $label,
            'from' => $pendingOnly ? null : $from->toDateString(),
            'to' => $pendingOnly ? null : $to->toDateString(),
            'timezone' => $tz,
            'currency' => $currency,
            'po_count' => $count,
            'grand_total' => $canFinancials ? $total : null,
            'grand_total_visible' => $canFinancials,
            'statuses_included' => $statuses,
            'excluded' => $pendingOnly ? ['draft', 'approved', 'rejected', 'cancelled'] : ['draft', 'rejected', 'cancelled'],
            'include_drafts' => (bool) ($arguments['include_drafts'] ?? false),
            'pending_only' => $pendingOnly,
            'project_id' => isset($arguments['project_id']) ? (int) $arguments['project_id'] : null,
            'vendor_id' => isset($arguments['vendor_id']) ? (int) $arguments['vendor_id'] : null,
            'breakdown' => $breakdown,
            'note' => $canFinancials
                ? 'Totals use purchase_orders.grand_total (header). Item rows were not joined.'
                : 'PO count is available; monetary totals require financial view permission.',
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function period(array $arguments, string $tz): array
    {
        $now = CarbonImmutable::now($tz);
        $period = $arguments['period'] ?? 'this_month';

        return match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay(), 'Today ('.$now->toDateString().')'],
            'last_month' => [
                $now->subMonth()->startOfMonth(),
                $now->subMonth()->endOfMonth(),
                $now->subMonth()->format('F Y'),
            ],
            'custom' => [
                CarbonImmutable::parse($arguments['from'] ?? $now->toDateString(), $tz)->startOfDay(),
                CarbonImmutable::parse($arguments['to'] ?? $now->toDateString(), $tz)->endOfDay(),
                'Custom range',
            ],
            default => [
                $now->startOfMonth(),
                $now->endOfMonth(),
                $now->format('F Y'),
            ],
        };
    }

    /**
     * @return list<string>
     */
    private function statuses(bool $includeDrafts): array
    {
        $committed = [
            PurchaseOrderStatus::Approved->value,
            PurchaseOrderStatus::PartiallyReceived->value,
            PurchaseOrderStatus::Received->value,
            PurchaseOrderStatus::Closed->value,
        ];

        if ($includeDrafts) {
            $committed[] = PurchaseOrderStatus::Draft->value;
            $committed[] = PurchaseOrderStatus::Submitted->value;
        }

        return $committed;
    }
}
