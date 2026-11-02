<?php

namespace App\Services\Ai\Tools;

use App\Enums\Finance\VendorBillStatus;
use App\Models\Finance\VendorBill;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Ai\Contracts\AiTool;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

class GetVendorOutstandingTool implements AiTool
{
    public function __construct(private readonly CurrentCompany $company) {}

    public function name(): string
    {
        return 'getVendorOutstanding';
    }

    public function description(): string
    {
        return 'List vendors with outstanding payable balances from approved/partially paid vendor bills.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => ['type' => 'integer'],
                'vendor_id' => ['type' => 'integer'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        if (! $user->can('vendor_bills.view')) {
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
            return ['error' => 'restricted', 'message' => 'Vendor outstanding information is restricted for your role.'];
        }

        $query = VendorBill::query()
            ->whereIn('status', [
                VendorBillStatus::Approved->value,
                VendorBillStatus::PartiallyPaid->value,
            ])
            ->whereRaw('net_payable > paid_amount');

        if (! empty($arguments['project_id'])) {
            $query->where('project_id', (int) $arguments['project_id']);
        } else {
            $query->whereIn('project_id', Project::query()->visibleTo($user)->select('id'));
        }
        if (! empty($arguments['vendor_id'])) {
            $query->where('vendor_id', (int) $arguments['vendor_id']);
        }

        $canMoney = $user->can('dashboard.view_financials') || $user->can('reports.view_financial');

        $rows = (clone $query)
            ->select('vendor_id', DB::raw('SUM(net_payable - paid_amount) as outstanding'), DB::raw('COUNT(*) as bill_count'))
            ->groupBy('vendor_id')
            ->orderByDesc('outstanding')
            ->limit(15)
            ->get();

        $vendors = Vendor::query()
            ->whereIn('id', $rows->pluck('vendor_id')->filter())
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        $list = $rows->map(fn ($row) => [
            'vendor_id' => $row->vendor_id,
            'vendor' => $vendors->get($row->vendor_id)?->name,
            'code' => $vendors->get($row->vendor_id)?->code,
            'bill_count' => (int) $row->bill_count,
            'outstanding' => $canMoney ? (string) $row->outstanding : null,
        ])->all();

        $total = $canMoney ? (string) ((clone $query)->selectRaw('SUM(net_payable - paid_amount) as t')->value('t') ?? '0') : null;

        return [
            'vendor_count' => count($list),
            'total_outstanding' => $total,
            'totals_visible' => $canMoney,
            'currency' => $this->company->get()?->currency ?: 'INR',
            'vendors' => $list,
        ];
    }
}
