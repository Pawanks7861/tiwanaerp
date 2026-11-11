<?php

namespace App\Http\Controllers\Masters;

use App\Http\Controllers\Controller;
use App\Models\Equipment\Equipment;
use App\Http\Requests\Masters\MasterRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Masters\MasterModel;
use App\Services\Masters\MasterService;
use App\Support\Masters\MasterDefinition;
use App\Support\Masters\MasterRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MasterController extends Controller
{
    public function __construct(private readonly MasterService $service) {}

    public function index(Request $request, string $master): Response
    {
        $definition = MasterRegistry::get($master);
        Gate::authorize('viewAny', $definition->model());

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : 'all',
        ];

        $query = $definition->model()::query()
            ->with($definition->with())
            ->search($filters['search'])
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('is_active', $filters['status'] === 'active'));
        $definition->applySort($query);

        $records = $query->paginate(25)->withQueryString()->through(fn (MasterModel $r) => $definition->row($r));

        return Inertia::render('Masters/Index', [
            'definition' => $definition->toFrontend(),
            'records' => $records,
            'filters' => $filters,
            'options' => $definition->options($request),
            'can' => $this->abilities($request, $definition),
        ]);
    }

    public function show(Request $request, string $master, int $record): Response|RedirectResponse
    {
        $definition = MasterRegistry::get($master);
        $model = $this->find($definition, $record);
        Gate::authorize('view', $model);

        if (! $definition->hasAttachments()) {
            return redirect()->route('masters.index', $master);
        }

        $model->load($definition->with());

        return Inertia::render('Masters/Show', [
            'definition' => $definition->toFrontend(),
            'record' => $definition->row($model),
            'options' => $definition->options($request),
            'attachments' => AttachmentResource::collection($model->attachments()->with('uploader:id,name')->get())->resolve(),
            'attachableType' => $model->getMorphClass(),
            'can' => $this->abilities($request, $definition),
            'locationHistory' => $model instanceof Equipment ? $this->locationHistory($model) : [],
            'locationTypes' => $model instanceof Equipment ? \App\Enums\Equipment\EquipmentLocationType::options() : [],
            'quantityMovements' => $model instanceof Equipment && $model->isQuantityTracked()
                ? $this->quantityMovements($model)
                : [],
            'quantityMovementTypes' => $model instanceof Equipment
                ? \App\Enums\Equipment\EquipmentQuantityMovementType::manualOptions()
                : [],
        ]);
    }

    public function store(MasterRequest $request, string $master): RedirectResponse
    {
        $definition = $request->definition();
        $record = $this->service->create($definition, $request->validated());

        return back()->with('success', "{$definition->singular()} {$this->label($definition, $record)} created.");
    }

    public function update(MasterRequest $request, string $master, int $record): RedirectResponse
    {
        $definition = $request->definition();
        $model = $this->service->update($definition, $request->record(), $request->validated());

        return back()->with('success', "{$definition->singular()} {$this->label($definition, $model)} updated.");
    }

    public function destroy(string $master, int $record): RedirectResponse
    {
        $definition = MasterRegistry::get($master);
        $model = $this->find($definition, $record);
        Gate::authorize('delete', $model);

        $this->service->delete($definition, $model);

        return redirect()->route('masters.index', $master)->with('success', "{$definition->singular()} deleted.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function locationHistory(Equipment $equipment): array
    {
        return $equipment->locationHistory()->with('changer:id,name')->limit(30)->get()->map(fn ($row) => [
            'id' => $row->id,
            'from' => trim(($row->from_location_type?->label() ?? '').' '.($row->from_location_name ?? '')),
            'to' => trim($row->to_location_type->label().' '.($row->to_location_name ?? '')),
            'remarks' => $row->remarks,
            'changed_by' => $row->changer?->name,
            'changed_at' => $row->changed_at?->toIso8601String(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quantityMovements(Equipment $equipment): array
    {
        return $equipment->quantityMovements()->with('creator:id,name')->limit(50)->get()->map(fn ($row) => [
            'id' => $row->id,
            'moved_at' => $row->moved_at?->toDateString(),
            'movement_type' => $row->movement_type?->value,
            'movement_type_label' => $row->movement_type?->label(),
            'quantity' => (string) $row->quantity,
            'reason' => $row->reason,
            'remarks' => $row->remarks,
            'created_by' => $row->creator?->name,
        ])->all();
    }

    private function find(MasterDefinition $definition, int $id): MasterModel
    {
        return $definition->model()::query()->findOrFail($id);
    }

    private function label(MasterDefinition $definition, MasterModel $record): string
    {
        return (string) ($record->getAttribute('code') ?? $record->getAttribute($definition->nameColumn()));
    }

    /**
     * @return array{create: bool, update: bool, delete: bool, import: bool}
     */
    private function abilities(Request $request, MasterDefinition $definition): array
    {
        $user = $request->user();
        $prefix = $definition->permission();

        return [
            'create' => $user->can("{$prefix}.create"),
            'update' => $user->can("{$prefix}.update"),
            'delete' => $user->can("{$prefix}.delete"),
            'import' => $user->can("{$prefix}.import"),
        ];
    }
}
