<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Inventory\InventoryDocumentStatus;
use App\Enums\Inventory\StockTransferStatus;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryScope $scope) {}

    public function summary(Request $request, Project $project): JsonResponse
    {
        $this->authorizeView($request, $project);

        $warehouseIds = $this->scope->warehouseIds($project);
        $stockItems = $warehouseIds === []
            ? 0
            : (int) StockBalance::query()->whereIn('warehouse_id', $warehouseIds)->where('quantity', '>', 0)->count();

        return response()->json([
            'data' => [
                'stock_items' => $stockItems,
                'pending_issues' => (int) MaterialIssue::query()
                    ->where('project_id', $project->id)
                    ->where('status', InventoryDocumentStatus::Submitted->value)
                    ->count(),
                'transfers_in_transit' => (int) StockTransfer::query()
                    ->where('project_id', $project->id)
                    ->whereIn('status', [StockTransferStatus::Dispatched->value, StockTransferStatus::PartiallyReceived->value])
                    ->count(),
                'pending_returns' => (int) MaterialReturn::query()
                    ->where('project_id', $project->id)
                    ->where('status', InventoryDocumentStatus::Submitted->value)
                    ->count(),
                'pending_adjustments' => (int) StockAdjustment::query()
                    ->where('project_id', $project->id)
                    ->where('status', InventoryDocumentStatus::Submitted->value)
                    ->count(),
                'can' => [
                    'create_issue' => Gate::allows('create', [MaterialIssue::class, $project]),
                    'create_transfer' => Gate::allows('create', [StockTransfer::class, $project]),
                    'create_return' => Gate::allows('create', [MaterialReturn::class, $project]),
                    'create_adjustment' => Gate::allows('create', [StockAdjustment::class, $project]),
                    'view_valuation' => InventoryPresenter::seesValuation($request->user()),
                ],
            ],
        ]);
    }

    public function stock(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorizeView($request, $project);

        $filters = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'item_type' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $warehouseIds = $this->scope->warehouseIds($project);
        $valuation = InventoryPresenter::seesValuation($request->user());
        $search = trim((string) ($filters['search'] ?? ''));

        $balances = StockBalance::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity', '>', 0)
            ->when(! empty($filters['warehouse_id']), function ($q) use ($filters, $warehouseIds) {
                $id = (int) $filters['warehouse_id'];
                abort_unless(in_array($id, $warehouseIds, true), 404);
                $q->where('warehouse_id', $id);
            })
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->whereHas('material', fn ($m) => $m->where('name', 'like', $term)->orWhere('code', 'like', $term));
            })
            ->when(! empty($filters['item_type']), fn ($q) => $q->whereHas('material', fn ($m) => $m->where('item_type', $filters['item_type'])))
            ->with([
                'warehouse' => fn ($q) => $q->withTrashed(),
                'material' => fn ($q) => $q->withTrashed()->with('unit:id,symbol,name'),
            ])
            ->join('materials as m', 'm.id', '=', 'stock_balances.material_id')
            ->join('warehouses as w', 'w.id', '=', 'stock_balances.warehouse_id')
            ->orderBy('m.name')
            ->orderBy('w.name')
            ->select('stock_balances.*')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

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

    public function stockShow(Request $request, Project $project): JsonResponse
    {
        $this->authorizeView($request, $project);

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'material_id' => ['required', 'integer'],
        ]);

        $warehouseIds = $this->scope->warehouseIds($project);
        abort_unless(in_array((int) $data['warehouse_id'], $warehouseIds, true), 404);

        $balance = StockBalance::query()
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('material_id', $data['material_id'])
            ->with([
                'warehouse' => fn ($q) => $q->withTrashed(),
                'material' => fn ($q) => $q->withTrashed()->with('unit:id,symbol,name'),
            ])
            ->firstOrFail();

        $recent = StockTransaction::query()
            ->where('warehouse_id', $balance->warehouse_id)
            ->where('material_id', $balance->material_id)
            ->orderByDesc('txn_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $refs = InventoryPresenter::references($recent, $project);
        $valuation = InventoryPresenter::seesValuation($request->user());

        return response()->json([
            'data' => [
                ...(new StockBalanceResource($balance))->resolve($request),
                'material' => $balance->material?->only(['id', 'code', 'name', 'item_type', 'description']),
                'warehouse' => $balance->warehouse?->only(['id', 'code', 'name', 'type', 'project_id']),
                'recent_transactions' => $recent->map(fn (StockTransaction $txn) => [
                    ...(new StockTransactionResource($txn))->resolve($request),
                    'reference' => $refs[$txn->source_type.':'.$txn->source_id] ?? null,
                ])->values(),
                'can' => [
                    'view_valuation' => $valuation,
                ],
            ],
        ]);
    }

    public function available(Request $request, Project $project): JsonResponse
    {
        $this->authorizeView($request, $project);

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'material_id' => ['nullable', 'integer'],
        ]);

        abort_unless($this->scope->contains($project, (int) $data['warehouse_id']), 404);

        $qty = StockBalance::query()
            ->where('warehouse_id', $data['warehouse_id'])
            ->when(! empty($data['material_id']), fn ($q) => $q->where('material_id', $data['material_id']))
            ->get(['material_id', 'quantity']);

        return response()->json([
            'data' => $qty->map(fn (StockBalance $b) => [
                'material_id' => $b->material_id,
                'quantity' => $b->quantity,
            ])->values(),
        ]);
    }

    public function ledger(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorizeView($request, $project);

        $filters = $request->validate([
            'material_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'txn_type' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $warehouseIds = $this->scope->warehouseIds($project);

        $movements = StockTransaction::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->when(! empty($filters['warehouse_id']), function ($q) use ($filters, $warehouseIds) {
                $id = (int) $filters['warehouse_id'];
                abort_unless(in_array($id, $warehouseIds, true), 404);
                $q->where('warehouse_id', $id);
            })
            ->when(! empty($filters['material_id']), fn ($q) => $q->where('material_id', $filters['material_id']))
            ->when(! empty($filters['txn_type']), fn ($q) => $q->where('txn_type', $filters['txn_type']))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('txn_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('txn_date', '<=', $filters['to']))
            ->with([
                'warehouse' => fn ($q) => $q->withTrashed(),
                'material' => fn ($q) => $q->withTrashed()->with('unit:id,symbol'),
            ])
            ->orderByDesc('txn_date')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        $refs = InventoryPresenter::references(collect($movements->items()), $project);
        $movements->getCollection()->transform(function (StockTransaction $txn) use ($refs) {
            $txn->setAttribute('reference_meta', $refs[$txn->source_type.':'.$txn->source_id] ?? null);

            return $txn;
        });

        return StockTransactionResource::collection($movements);
    }

    public function issues(Request $request, Project $project): AnonymousResourceCollection
    {
        return app(MaterialIssueController::class)->index($request, $project);
    }

    public function transfers(Request $request, Project $project): AnonymousResourceCollection
    {
        return app(StockTransferController::class)->index($request, $project);
    }

    public function returns(Request $request, Project $project): AnonymousResourceCollection
    {
        return app(MaterialReturnController::class)->index($request, $project);
    }

    public function adjustments(Request $request, Project $project): AnonymousResourceCollection
    {
        return app(StockAdjustmentController::class)->index($request, $project);
    }

    private function authorizeView(Request $request, Project $project): void
    {
        abort_unless($request->user()->can('inventory.view') && $request->user()->can('view', $project), 403);
    }

    private function money(mixed $value): string
    {
        if ($value === null) {
            return Decimal::zero()->toMoney();
        }

        return Decimal::of(is_float($value) ? sprintf('%.6F', $value) : (string) $value)->toMoney();
    }
}
