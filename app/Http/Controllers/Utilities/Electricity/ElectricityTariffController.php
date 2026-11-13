<?php

namespace App\Http\Controllers\Utilities\Electricity;

use App\Enums\IndianState;
use App\Http\Controllers\Controller;
use App\Models\Utilities\ElectricityConnection;
use App\Models\Utilities\ElectricityTariff;
use App\Rules\ExistsInCompany;
use App\Services\Utilities\ElectricityTariffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ElectricityTariffController extends Controller
{
    public function __construct(private readonly ElectricityTariffService $tariffs) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ElectricityTariff::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'electricity_connection_id' => ['nullable'],
        ]);

        $page = ElectricityTariff::query()
            ->with('connection:id,connection_number')
            ->withCount('components')
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('tariff_category', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when(isset($filters['electricity_connection_id']) && $filters['electricity_connection_id'] !== 'all', fn ($q) => $q->where('electricity_connection_id', $filters['electricity_connection_id']))
            ->orderByDesc('effective_from')
            ->paginate(25)
            ->withQueryString();

        $user = $request->user();
        $withCosts = ElectricityPresenter::seesCosts($user);

        return Inertia::render('Utilities/Electricity/Tariffs/Index', [
            'tariffs' => $page->through(fn (ElectricityTariff $t) => ElectricityPresenter::tariff($t, $withCosts)),
            'filters' => $filters,
            'connections' => ElectricityPresenter::connectionFilterOptions(),
            'can' => ['create' => $user->can('create', ElectricityTariff::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', ElectricityTariff::class);

        return $this->form(null, $request);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ElectricityTariff::class);

        $data = $this->validated($request);
        $connection = ElectricityConnection::query()->findOrFail($data['electricity_connection_id']);
        $tariff = $this->tariffs->create($connection, $data, $data['components'] ?? []);

        return redirect()->route('utilities.electricity.tariffs.show', $tariff)->with('success', 'Tariff created.');
    }

    public function show(Request $request, ElectricityTariff $electricityTariff): Response
    {
        Gate::authorize('view', $electricityTariff);
        $user = $request->user();
        $withCosts = ElectricityPresenter::seesCosts($user);
        $electricityTariff->load(['connection:id,connection_number,utility_site_id', 'components']);

        return Inertia::render('Utilities/Electricity/Tariffs/Show', [
            'tariff' => ElectricityPresenter::tariff($electricityTariff, $withCosts),
            'components' => $electricityTariff->components
                ->map(fn ($c) => ElectricityPresenter::tariffComponent($c, $withCosts))->all(),
            'can' => ['update' => $user->can('update', $electricityTariff)],
        ]);
    }

    public function edit(ElectricityTariff $electricityTariff): Response
    {
        Gate::authorize('update', $electricityTariff);
        $electricityTariff->load('components');

        return $this->form($electricityTariff, request());
    }

    public function update(Request $request, ElectricityTariff $electricityTariff): RedirectResponse
    {
        Gate::authorize('update', $electricityTariff);

        $data = $this->validated($request);
        $this->tariffs->update($electricityTariff, $data, $data['components'] ?? null);

        return redirect()->route('utilities.electricity.tariffs.show', $electricityTariff)->with('success', 'Tariff updated.');
    }

    public function destroy(ElectricityTariff $electricityTariff): RedirectResponse
    {
        Gate::authorize('delete', $electricityTariff);

        $electricityTariff->delete();

        return redirect()->route('utilities.electricity.tariffs.index')->with('success', 'Tariff removed.');
    }

    private function form(?ElectricityTariff $tariff, Request $request): Response
    {
        $withCosts = ElectricityPresenter::seesCosts($request->user());
        $connectionId = $request->query('electricity_connection_id');
        $payload = $tariff
            ? ElectricityPresenter::tariff($tariff, $withCosts)
            : ($connectionId ? ['electricity_connection_id' => (int) $connectionId] : null);

        return Inertia::render('Utilities/Electricity/Tariffs/Form', [
            'tariff' => $payload,
            'connections' => ElectricityPresenter::connectionFilterOptions(),
            'component_types' => ElectricityPresenter::componentTypeOptions(),
            'calculation_bases' => ElectricityPresenter::calculationBasisOptions(),
            'states' => IndianState::options(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $rules = [
            'electricity_connection_id' => ['required', new ExistsInCompany(ElectricityConnection::class)],
            'name' => ['required', 'string', 'max:160'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'tariff_category' => ['nullable', 'string', 'max:80'],
            'state_code' => ['nullable', Rule::enum(IndianState::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'components' => ['nullable', 'array'],
            ...ElectricityTariffService::componentRules(),
        ];

        return $request->validate($rules);
    }
}
