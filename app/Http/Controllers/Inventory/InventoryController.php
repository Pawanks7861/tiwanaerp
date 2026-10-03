<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\Inventory\StockTxnType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Masters\Material;
use App\Models\Masters\MaterialCategory;
use App\Models\Masters\Warehouse;
use App\Models\Projects\Project;
use App\Policies\ProjectPolicy;
use App\Support\Inventory\InventoryScope;
use App\Support\Math\Decimal;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock overview and ledger drill-down for the stores reachable from a project (its site stores
 * plus the company's central stores). Read only: balances change only through posted documents.
 */
class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryScope $scope,
        private readonly ProjectPolicy $projects,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        $user = $request->user();
        abort_unless($user->can('inventory.view') && $this->projects->view($user, $project), 403);

        $warehouseIds = $this->scope->warehouseIds($project);
        $filters = $request->validate([
            'warehouse' => ['nullable', 'integer', Rule::in($warehouseIds)],
            'material' => ['nullable', 'integer'],
            'category' => ['nullable', 'integer'],
            'low' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $valuation = InventoryPresenter::seesValuation($user);
        $low = (bool) ($filters['low'] ?? false);

        $page = DB::table('stock_balances as b')
            ->join('materials as m', 'm.id', '=', 'b.material_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('units as u', 'u.id', '=', 'm.unit_id')
            ->leftJoin('material_categories as c', 'c.id', '=', 'm.material_category_id')
            ->where('b.company_id', app(CurrentCompany::class)->require()->id)
            ->whereIn('b.warehouse_id', $warehouseIds)
            ->when($filters['warehouse'] ?? null, fn ($q, $id) => $q->where('b.warehouse_id', $id))
            ->when($filters['material'] ?? null, fn ($q, $id) => $q->where('b.material_id', $id))
            ->when($filters['category'] ?? null, fn ($q, $id) => $q->where('m.material_category_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('m.name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('m.code', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($low,
                fn ($q) => $q->where('m.reorder_level', '>', 0)->whereColumn('b.quantity', '<=', 'm.reorder_level'),
                fn ($q) => $q->where('b.quantity', '>', 0))
            ->orderBy('m.name')->orderBy('w.name')
            ->select(['b.warehouse_id', 'b.material_id', 'b.quantity', 'b.avg_cost', 'b.value', 'm.code', 'm.name', 'm.reorder_level',
                'u.symbol as unit', 'c.name as category', 'w.name as warehouse', 'w.code as warehouse_code', 'w.project_id as warehouse_project_id'])
            ->paginate(50)
            ->withQueryString();

        $summary = DB::table('stock_balances as b')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->where('b.company_id', app(CurrentCompany::class)->require()->id)
            ->whereIn('b.warehouse_id', $warehouseIds)
            ->where('b.quantity', '>', 0)
            ->groupBy('b.warehouse_id', 'w.name', 'w.code', 'w.project_id')
            ->selectRaw('b.warehouse_id, w.name, w.code, w.project_id, COUNT(*) as items, SUM(b.value) as value')
            ->orderBy('w.name')
            ->get();

        return Inertia::render('Inventory/Stock/Index', [
            'project' => ProjectHeader::for($project),
            'rows' => $page->through(fn ($row) => [
                'warehouse_id' => $row->warehouse_id,
                'material_id' => $row->material_id,
                'code' => $row->code,
                'name' => $row->name,
                'category' => $row->category,
                'warehouse' => $row->warehouse,
                'central' => $row->warehouse_project_id === null,
                'unit' => $row->unit,
                'quantity' => $this->dec($row->quantity, 4),
                'reorder_level' => $this->dec($row->reorder_level, 4),
                'low' => Decimal::of($this->dec($row->reorder_level, 4))->isPositive()
                    && Decimal::of($this->dec($row->quantity, 4))->lessThanOrEqual($this->dec($row->reorder_level, 4)),
                ...($valuation ? ['avg_cost' => $this->dec($row->avg_cost, 4), 'value' => $this->dec($row->value, 2)] : []),
            ]),
            'warehouses' => $summary->map(fn ($w) => [
                'id' => $w->warehouse_id,
                'name' => $w->name,
                'code' => $w->code,
                'central' => $w->project_id === null,
                'items' => (int) $w->items,
                ...($valuation ? ['value' => $this->dec($w->value, 2)] : []),
            ])->all(),
            'filters' => $filters,
            'options' => [
                'warehouses' => $this->scope->options($project),
                'materials' => InventoryPresenter::materialOptions(),
                'categories' => MaterialCategory::query()->active()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (MaterialCategory $c) => ['value' => $c->id, 'label' => $c->name])->all(),
            ],
            'can' => ['view_valuation' => $valuation],
        ]);
    }

    /**
     * Ledger rows of the reachable stores. With one warehouse and one material selected, each row
     * carries the running balance (window function over the posting order).
     */
    public function ledger(Request $request, Project $project): Response
    {
        $user = $request->user();
        abort_unless($user->can('inventory.view') && $this->projects->view($user, $project), 403);

        $warehouseIds = $this->scope->warehouseIds($project);
        $filters = $request->validate([
            'warehouse' => ['nullable', 'integer', Rule::in($warehouseIds)],
            'material' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(StockTxnType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $valuation = InventoryPresenter::seesValuation($user);
        $companyId = app(CurrentCompany::class)->require()->id;
        $running = isset($filters['warehouse'], $filters['material']);

        $base = DB::table('stock_transactions')
            ->where('company_id', $companyId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->when($filters['warehouse'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['material'] ?? null, fn ($q, $id) => $q->where('material_id', $id));

        if ($running) {
            $base->select('stock_transactions.*')
                ->selectRaw('SUM(qty_in - qty_out) OVER (ORDER BY id) as running_qty')
                ->selectRaw('SUM(CASE WHEN qty_in > 0 THEN value ELSE -value END) OVER (ORDER BY id) as running_value');
        }

        $page = DB::query()->fromSub($base, 't')
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('txn_type', $type))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->where('txn_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where('txn_date', '<=', $date))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $rows = collect($page->items());
        $refs = InventoryPresenter::references($rows, $project);
        $materials = Material::query()->withTrashed()->with('unit:id,symbol')->whereIn('id', $rows->pluck('material_id')->unique())->get(['id', 'code', 'name', 'unit_id'])->keyBy('id');
        $warehouses = Warehouse::query()->withTrashed()->whereIn('id', $rows->pluck('warehouse_id')->unique())->pluck('name', 'id');

        return Inertia::render('Inventory/Stock/Ledger', [
            'project' => ProjectHeader::for($project),
            'rows' => $page->through(function ($row) use ($project, $refs, $materials, $warehouses, $valuation, $running) {
                $own = (int) $row->project_id === $project->id;
                $material = $materials->get($row->material_id);

                return [
                    'id' => $row->id,
                    'txn_date' => substr((string) $row->txn_date, 0, 10),
                    'txn_type' => $row->txn_type,
                    'type_label' => InventoryPresenter::txnLabel($row->txn_type),
                    'warehouse' => $warehouses[$row->warehouse_id] ?? null,
                    'material' => $material ? ['code' => $material->code, 'name' => $material->name, 'unit' => $material->unit?->symbol] : null,
                    'qty_in' => $this->dec($row->qty_in, 4),
                    'qty_out' => $this->dec($row->qty_out, 4),
                    'running_qty' => $running ? $this->dec($row->running_qty, 4) : null,
                    'reference' => $own ? ($refs["{$row->source_type}:{$row->source_id}"] ?? null) : null,
                    'other_project' => ! $own,
                    'remarks' => $own ? $row->remarks : null,
                    'reverses_id' => $row->reverses_id,
                    ...($valuation ? [
                        'unit_cost' => $this->dec($row->unit_cost, 4),
                        'value' => $this->dec($row->value, 2),
                        'running_value' => $running ? $this->dec($row->running_value, 2) : null,
                    ] : []),
                ];
            }),
            'filters' => $filters,
            'running' => $running,
            'options' => [
                'warehouses' => $this->scope->options($project),
                'materials' => InventoryPresenter::materialOptions(),
                'types' => StockTxnType::options(),
            ],
            'can' => ['view_valuation' => $valuation],
        ]);
    }

    /**
     * Normalise DB numerics (exact strings on MySQL, floats on SQLite) to fixed-scale strings.
     */
    private function dec(mixed $value, int $scale): string
    {
        if ($value === null) {
            return Decimal::zero()->round($scale)->toString();
        }

        return Decimal::of(is_float($value) ? sprintf('%.6F', $value) : (string) $value)->round($scale)->toString();
    }
}
