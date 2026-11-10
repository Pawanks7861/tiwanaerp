<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Inventory\StockTransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockTransferResource;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\StockTransferReceipt;
use App\Models\Projects\Project;
use App\Services\Inventory\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StockTransferController extends Controller
{
    public function __construct(private readonly StockTransferService $transfers) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [StockTransfer::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(StockTransferStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $rows = StockTransfer::query()
            ->where('project_id', $project->id)
            ->with(['fromWarehouse:id,code,name', 'toWarehouse:id,code,name'])
            ->withCount('items')
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('transfer_number', 'like', $term)
                    ->orWhereHas('fromWarehouse', fn ($h) => $h->where('name', 'like', $term)->orWhere('code', 'like', $term))
                    ->orWhereHas('toWarehouse', fn ($h) => $h->where('name', 'like', $term)->orWhere('code', 'like', $term)));
            })
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return StockTransferResource::collection($rows);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [StockTransfer::class, $project]);
        $transfer = $this->transfers->create($project, $this->validated($request));

        return (new StockTransferResource($this->detail($transfer)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, StockTransfer $stockTransfer): StockTransferResource
    {
        abort_unless((int) $stockTransfer->project_id === (int) $project->id, 404);
        Gate::authorize('view', $stockTransfer);

        return new StockTransferResource($this->detail($stockTransfer));
    }

    public function update(Request $request, Project $project, StockTransfer $stockTransfer): StockTransferResource
    {
        abort_unless((int) $stockTransfer->project_id === (int) $project->id, 404);
        Gate::authorize('update', $stockTransfer);
        $transfer = $this->transfers->update($stockTransfer, $this->validated($request));

        return new StockTransferResource($this->detail($transfer));
    }

    public function dispatchTransfer(Request $request, Project $project, StockTransfer $stockTransfer): StockTransferResource
    {
        abort_unless((int) $stockTransfer->project_id === (int) $project->id, 404);
        Gate::authorize('dispatch', $stockTransfer);
        $this->transfers->dispatch($stockTransfer, $request->user());

        return new StockTransferResource($this->detail($stockTransfer->fresh()));
    }

    public function receive(Request $request, Project $project, StockTransfer $stockTransfer): StockTransferResource
    {
        abort_unless((int) $stockTransfer->project_id === (int) $project->id, 404);

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:64'],
            'receipt_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.stock_transfer_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999'],
        ]);

        $already = StockTransferReceipt::query()
            ->where('stock_transfer_id', $stockTransfer->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->exists();

        $already
            ? abort_unless($request->user()->can('inventory.transfer') && $request->user()->can('view', $stockTransfer), 403)
            : Gate::authorize('receive', $stockTransfer);

        $this->transfers->receive($stockTransfer, $request->user(), $data);

        return new StockTransferResource($this->detail($stockTransfer->fresh()));
    }

    public function cancel(Request $request, Project $project, StockTransfer $stockTransfer): StockTransferResource
    {
        abort_unless((int) $stockTransfer->project_id === (int) $project->id, 404);
        Gate::authorize('cancel', $stockTransfer);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->transfers->cancel($stockTransfer, $request->user(), $reason);

        return new StockTransferResource($this->detail($stockTransfer->fresh()));
    }

    public function closeShort(Request $request, Project $project, StockTransfer $stockTransfer): StockTransferResource
    {
        abort_unless((int) $stockTransfer->project_id === (int) $project->id, 404);
        Gate::authorize('closeShort', $stockTransfer);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $this->transfers->closeShort($stockTransfer, $request->user(), $reason);

        return new StockTransferResource($this->detail($stockTransfer->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'transfer_date' => ['required', 'date', 'before_or_equal:today'],
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id'],
            'vehicle_no' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.material_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function detail(StockTransfer $transfer): StockTransfer
    {
        return $transfer->load([
            'fromWarehouse:id,code,name,type,project_id',
            'toWarehouse:id,code,name,type,project_id',
            'items.material:id,code,name,item_type',
            'items.unit:id,symbol',
            'receipts',
            'attachments.uploader:id,name',
        ])->loadCount('items');
    }
}
