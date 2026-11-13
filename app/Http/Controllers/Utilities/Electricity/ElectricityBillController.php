<?php

namespace App\Http\Controllers\Utilities\Electricity;

use App\Enums\Utilities\ElectricityBillStatus;
use App\Http\Controllers\Controller;
use App\Models\Utilities\ElectricityBill;
use App\Models\Utilities\ElectricityMeterReading;
use App\Models\Utilities\ElectricityTariff;
use App\Rules\ExistsInCompany;
use App\Services\Utilities\ElectricityBillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ElectricityBillController extends Controller
{
    public function __construct(private readonly ElectricityBillService $bills) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ElectricityBill::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'utility_site_id' => ['nullable'],
            'status' => ['nullable'],
        ]);

        $page = ElectricityBill::query()
            ->with('site:id,name')
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where('bill_number', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->when(isset($filters['utility_site_id']) && $filters['utility_site_id'] !== 'all', fn ($q) => $q->where('utility_site_id', $filters['utility_site_id']))
            ->when(isset($filters['status']) && $filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->latest('billing_month')
            ->paginate(25)
            ->withQueryString();

        $user = $request->user();
        $withCosts = ElectricityPresenter::seesCosts($user);

        return Inertia::render('Utilities/Electricity/Bills/Index', [
            'bills' => $page->through(fn (ElectricityBill $b) => ElectricityPresenter::bill($b, $withCosts)),
            'filters' => $filters,
            'sites' => ElectricityPresenter::siteFilterOptions(),
            'statuses' => ElectricityBillStatus::options(),
            'can' => ['create' => $user->can('create', ElectricityBill::class)],
            'can_view_costs' => $withCosts,
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', ElectricityBill::class);

        return $this->form(null, $request);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ElectricityBill::class);

        $data = $this->validated($request, creating: true);
        $reading = ElectricityMeterReading::query()->findOrFail($data['electricity_meter_reading_id']);
        Gate::authorize('view', $reading);

        $bill = $this->bills->createFromReading($reading, $data);
        if (! empty($data['actual_bill_total']) || ! empty($data['component_actuals'])) {
            $this->bills->enterActual($bill, $data, $this->componentActuals($data, $bill->fresh('components')));
        }

        return redirect()->route('utilities.electricity.bills.show', $bill)->with('success', 'Bill created.');
    }

    public function show(Request $request, ElectricityBill $electricityBill): Response
    {
        Gate::authorize('view', $electricityBill);
        $user = $request->user();
        $withCosts = ElectricityPresenter::seesCosts($user);
        $electricityBill->load(['site', 'connection', 'components']);

        return Inertia::render('Utilities/Electricity/Bills/Show', [
            'bill' => ElectricityPresenter::bill($electricityBill, $withCosts),
            'components' => $electricityBill->components
                ->map(fn ($c) => ElectricityPresenter::billComponent($c, $withCosts))->all(),
            'can' => ['update' => $user->can('update', $electricityBill)],
            'can_view_costs' => $withCosts,
        ]);
    }

    public function edit(ElectricityBill $electricityBill): Response
    {
        Gate::authorize('update', $electricityBill);
        $electricityBill->load('components');

        return $this->form($electricityBill, request());
    }

    public function update(Request $request, ElectricityBill $electricityBill): RedirectResponse
    {
        Gate::authorize('update', $electricityBill);

        $data = $this->validated($request, creating: false);
        $electricityBill->loadMissing('components');
        $this->bills->enterActual($electricityBill, $data, $this->componentActuals($data, $electricityBill));

        return redirect()->route('utilities.electricity.bills.show', $electricityBill)->with('success', 'Bill updated.');
    }

    public function recalculate(ElectricityBill $electricityBill): RedirectResponse
    {
        Gate::authorize('update', $electricityBill);

        $this->bills->recalculate($electricityBill);

        return back()->with('success', 'Bill recalculated.');
    }

    public function review(Request $request, ElectricityBill $electricityBill): RedirectResponse
    {
        Gate::authorize('review', $electricityBill);

        $this->bills->markReviewed($electricityBill, $request->user());

        return back()->with('success', 'Bill marked as reviewed.');
    }

    public function approve(Request $request, ElectricityBill $electricityBill): RedirectResponse
    {
        Gate::authorize('approve', $electricityBill);

        $this->bills->approve($electricityBill, $request->user());

        return back()->with('success', 'Bill approved.');
    }

    private function form(?ElectricityBill $bill, Request $request): Response
    {
        $user = $request->user();
        $withCosts = ElectricityPresenter::seesCosts($user);
        $readingId = $request->query('electricity_meter_reading_id');
        $payload = $bill
            ? [
                ...ElectricityPresenter::bill($bill, $withCosts),
                'components' => $bill->components
                    ->map(fn ($c) => ElectricityPresenter::billComponent($c, $withCosts))->all(),
            ]
            : ($readingId ? ['electricity_meter_reading_id' => (int) $readingId] : null);

        return Inertia::render('Utilities/Electricity/Bills/Form', [
            'bill' => $payload,
            'readings' => ElectricityPresenter::readingOptionsForBill(),
            'tariffs' => ElectricityTariff::query()->with('connection:id,connection_number')
                ->where('is_active', true)->orderBy('name')->limit(200)
                ->get()
                ->map(fn (ElectricityTariff $t) => [
                    'value' => $t->id,
                    'label' => $t->name,
                    'description' => $t->connection?->connection_number,
                ])->all(),
            'can_view_costs' => $withCosts,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating = true): array
    {
        $rules = [
            'electricity_tariff_id' => ['nullable', new ExistsInCompany(ElectricityTariff::class)],
            'bill_number' => ['nullable', 'string', 'max:60'],
            'billing_month' => ['nullable', 'date'],
            'bill_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'actual_bill_total' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'component_actuals' => ['nullable', 'array'],
            'component_actuals.*.component_code' => ['nullable', 'string'],
            'component_actuals.*.actual_amount' => ['nullable', 'numeric'],
        ];
        if ($creating) {
            $rules['electricity_meter_reading_id'] = ['required', new ExistsInCompany(ElectricityMeterReading::class)];
        }

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{component_code: string, actual_amount: mixed}>
     */
    private function componentActuals(array $data, ?ElectricityBill $bill = null): array
    {
        $rows = [];
        foreach ($data['component_actuals'] ?? [] as $index => $row) {
            $code = $row['component_code'] ?? null;
            if (! $code && $bill) {
                $code = $bill->components->get($index)?->component_code;
            }
            if ($code) {
                $rows[] = ['component_code' => $code, 'actual_amount' => $row['actual_amount'] ?? null];
            }
        }

        return $rows;
    }
}
