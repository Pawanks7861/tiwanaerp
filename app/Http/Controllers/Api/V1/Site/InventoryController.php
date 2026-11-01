<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\InventoryPresenter;
use App\Http\Resources\InventoryStoreResource;
use App\Http\Resources\MaterialIssueResource;
use App\Http\Resources\MaterialReturnResource;
use App\Http\Resources\StockAdjustmentResource;
use App\Http\Resources\StockBalanceResource;
use App\Http\Resources\StockTransactionResource;
use App\Http\Resources\StockTransferResource;
use App\Models\Inventory\MaterialIssue;
use App\Models\Inventory\MaterialReturn;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockTransaction;
use App\Models\Inventory\StockTransfer;
use App\Models\Masters\Warehouse;
use App\Models\Projects\Project;
use App\Support\Inventory\InventoryScope;
use App\Support\Math\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryScope $scope) {}

    public function stock(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorizeView($request, $project);

        $warehouseIds = $this->scope->warehouseIds($project);
        $valuation = InventoryPresenter::seesValuation($request->user());

        $balances = StockBalance::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity', '>', 0)
            ->with([
                'warehouse' => fn ($q) => $q->withTrashed(),
                'material' => fn ($q) => $q->withTrashed()->with('unit:id,symbol'),
            ])
            ->join('materials as m', 'm.id', '=', 'stock_balances.material_id')
            ->join('warehouses as w', 'w.id', '=', 'stock_balances.warehouse_id')
            ->orderBy('m.name')
            ->orderBy('w.name')
            ->select('stock_balances.*')
            ->paginate($this->perPage($request));

        $grouped = StockBalance::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity', '>', 0)
            ->selectRaw('warehouse_id, COUNT(*) as items, SUM(value) as value')
            ->groupBy('warehouse_id')
            ->get()
            ->keyBy('warehouse_id');

        $stores = Warehouse::query()->withTrashed()
            ->whereIn('id', $grouped->keys())
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'code' => $store->code,
                'item_count' => (int) $grouped[$store->id]->items,
                'total_value' => $valuation ? $this->money($grouped[$store->id]->value) : null,
            ]);

        return StockBalanceResource::collection($balances)
            ->additional(['stores' => InventoryStoreResource::collection($stores)->resolve($request)]);
    }

    public function ledger(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorizeView($request, $project);

        $movements = StockTransaction::query()
            ->whereIn('warehouse_id', $this->scope->warehouseIds($project))
            ->with([
                'warehouse' => fn ($q) => $q->withTrashed(),
                'material' => fn ($q) => $q->withTrashed()->with('unit:id,symbol'),
            ])
            ->orderByDesc('txn_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return StockTransactionResource::collection($movements);
    }

    public function issues(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [MaterialIssue::class, $project]);

        $issues = MaterialIssue::query()
            ->where('project_id', $project->id)
            ->with(['warehouse:id,name', 'issuedToUser:id,name', 'subcontractor:id,name'])
            ->latest('id')
            ->paginate($this->perPage($request));

        return MaterialIssueResource::collection($issues);
    }

    public function transfers(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [StockTransfer::class, $project]);

        $transfers = StockTransfer::query()
            ->where('project_id', $project->id)
            ->with(['fromWarehouse:id,name', 'toWarehouse:id,name'])
            ->latest('id')
            ->paginate($this->perPage($request));

        return StockTransferResource::collection($transfers);
    }

    public function returns(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [MaterialReturn::class, $project]);

        $returns = MaterialReturn::query()
            ->where('project_id', $project->id)
            ->with('warehouse:id,name')
            ->latest('id')
            ->paginate($this->perPage($request));

        return MaterialReturnResource::collection($returns);
    }

    public function adjustments(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [StockAdjustment::class, $project]);

        $adjustments = StockAdjustment::query()
            ->where('project_id', $project->id)
            ->with('warehouse:id,name')
            ->latest('id')
            ->paginate($this->perPage($request));

        return StockAdjustmentResource::collection($adjustments);
    }

    private function authorizeView(Request $request, Project $project): void
    {
        abort_unless($request->user()->can('inventory.view') && $request->user()->can('view', $project), 403);
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }

    private function money(mixed $value): string
    {
        if ($value === null) {
            return Decimal::zero()->toMoney();
        }

        return Decimal::of(is_float($value) ? sprintf('%.6F', $value) : (string) $value)->toMoney();
    }
}
