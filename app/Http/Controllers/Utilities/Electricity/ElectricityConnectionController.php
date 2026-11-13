<?php

namespace App\Http\Controllers\Utilities\Electricity;

use App\Enums\IndianState;
use App\Http\Controllers\Controller;
use App\Models\Masters\Vendor;
use App\Models\Utilities\ElectricityConnection;
use App\Models\Utilities\UtilitySite;
use App\Rules\ExistsInCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ElectricityConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ElectricityConnection::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'utility_site_id' => ['nullable'],
            'active' => ['nullable', Rule::in(['all', '1', '0'])],
        ]);

        $page = ElectricityConnection::query()
            ->with('site:id,code,name')
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('connection_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('consumer_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('discom_name', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when(isset($filters['utility_site_id']) && $filters['utility_site_id'] !== 'all', fn ($q) => $q->where('utility_site_id', $filters['utility_site_id']))
            ->when(($filters['active'] ?? 'all') === '1', fn ($q) => $q->where('is_active', true))
            ->when(($filters['active'] ?? 'all') === '0', fn ($q) => $q->where('is_active', false))
            ->orderBy('connection_number')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Utilities/Electricity/Connections/Index', [
            'connections' => $page->through(fn (ElectricityConnection $c) => ElectricityPresenter::connection($c)),
            'filters' => $filters,
            'sites' => ElectricityPresenter::siteFilterOptions(),
            'can' => ['create' => $request->user()->can('create', ElectricityConnection::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', ElectricityConnection::class);

        return $this->form(null, $request);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ElectricityConnection::class);

        $data = $this->validated($request);
        $connection = ElectricityConnection::query()->create($data);

        return redirect()->route('utilities.electricity.connections.show', $connection)->with('success', 'Connection created.');
    }

    public function show(Request $request, ElectricityConnection $electricityConnection): Response
    {
        Gate::authorize('view', $electricityConnection);
        $user = $request->user();
        $electricityConnection->load(['site:id,code,name', 'vendor:id,code,company_name']);
        $meters = $electricityConnection->meters()->orderBy('meter_number')->get();
        $tariffs = $electricityConnection->tariffs()->withCount('components')->latest('effective_from')->limit(20)->get();

        return Inertia::render('Utilities/Electricity/Connections/Show', [
            'connection' => [
                ...ElectricityPresenter::connection($electricityConnection),
                'vendor' => $electricityConnection->vendor?->only(['id', 'code', 'company_name']),
            ],
            'meters' => $meters->map(fn ($m) => ElectricityPresenter::meter($m))->all(),
            'tariffs' => $tariffs->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'effective_from' => $t->effective_from?->toDateString(),
                'is_active' => $t->is_active,
                'components_count' => $t->components_count,
            ])->all(),
            'can' => [
                'update' => $user->can('update', $electricityConnection),
                'delete' => $user->can('delete', $electricityConnection),
                'create_meter' => $user->can('create', \App\Models\Utilities\ElectricityMeter::class),
                'create_tariff' => $user->can('create', \App\Models\Utilities\ElectricityTariff::class),
            ],
        ]);
    }

    public function edit(ElectricityConnection $electricityConnection): Response
    {
        Gate::authorize('update', $electricityConnection);

        return $this->form($electricityConnection, request());
    }

    public function update(Request $request, ElectricityConnection $electricityConnection): RedirectResponse
    {
        Gate::authorize('update', $electricityConnection);

        $electricityConnection->update($this->validated($request));

        return redirect()->route('utilities.electricity.connections.show', $electricityConnection)->with('success', 'Connection updated.');
    }

    public function destroy(ElectricityConnection $electricityConnection): RedirectResponse
    {
        Gate::authorize('delete', $electricityConnection);

        $electricityConnection->delete();

        return redirect()->route('utilities.electricity.connections.index')->with('success', 'Connection removed.');
    }

    private function form(?ElectricityConnection $connection, Request $request): Response
    {
        return Inertia::render('Utilities/Electricity/Connections/Form', [
            'connection' => $connection ? ElectricityPresenter::connection($connection) : null,
            'sites' => ElectricityPresenter::siteFilterOptions(),
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('company_name')
                ->get(['id', 'code', 'company_name'])
                ->map(fn (Vendor $v) => ['value' => $v->id, 'label' => $v->company_name, 'description' => $v->code])->all(),
            'states' => IndianState::options(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'utility_site_id' => ['required', new ExistsInCompany(UtilitySite::class)],
            'vendor_id' => ['nullable', new ExistsInCompany(Vendor::class)],
            'connection_number' => ['required', 'string', 'max:60'],
            'consumer_number' => ['nullable', 'string', 'max:60'],
            'discom_name' => ['nullable', 'string', 'max:120'],
            'state_code' => ['nullable', Rule::enum(IndianState::class)],
            'tariff_category' => ['nullable', 'string', 'max:80'],
            'voltage_level' => ['nullable', 'string', 'max:40'],
            'sanctioned_demand' => ['nullable', 'numeric', 'min:0'],
            'contract_demand' => ['nullable', 'numeric', 'min:0'],
            'demand_unit' => ['nullable', 'string', 'max:10'],
            'metering_type' => ['nullable', 'string', 'max:40'],
            'billing_cycle' => ['nullable', 'string', 'max:40'],
            'default_multiplication_factor' => ['nullable', 'numeric', 'min:0'],
            'low_pf_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'tod_enabled' => ['sometimes', 'boolean'],
            'kwh_enabled' => ['sometimes', 'boolean'],
            'kvah_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
