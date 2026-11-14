<?php

namespace App\Http\Controllers\Utilities\Electricity;

use App\Enums\Utilities\ElectricityReadingStatus;
use App\Http\Controllers\Controller;
use App\Models\Utilities\ElectricityBill;
use App\Models\Utilities\ElectricityConnection;
use App\Models\Utilities\ElectricityMeter;
use App\Models\Utilities\ElectricityMeterReading;
use App\Models\Utilities\ElectricityMeterTodReading;
use App\Models\Utilities\UtilitySite;
use App\Rules\ExistsInCompany;
use App\Services\Utilities\ElectricityMeterReadingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ElectricityMeterReadingController extends Controller
{
    public function __construct(private readonly ElectricityMeterReadingService $readings) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ElectricityMeterReading::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'utility_site_id' => ['nullable'],
            'status' => ['nullable'],
            'billing_month' => ['nullable', 'date'],
        ]);

        $page = ElectricityMeterReading::query()
            ->with([
                'site:id,code,name,state_code,city,address,is_active',
                'connection:id,connection_number',
                'meter:id,meter_number',
            ])
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->whereHas('meter', fn ($m) => $m->where('meter_number', 'like', '%'.addcslashes($term, '%_\\').'%'))
                ->orWhereHas('connection', fn ($c) => $c->where('connection_number', 'like', '%'.addcslashes($term, '%_\\').'%'))))
            ->when(isset($filters['utility_site_id']) && $filters['utility_site_id'] !== 'all', fn ($q) => $q->where('utility_site_id', $filters['utility_site_id']))
            ->when(isset($filters['status']) && $filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['billing_month'] ?? null, fn ($q, $d) => $q->whereDate('billing_month', Carbon::parse($d)->startOfMonth()))
            ->latest('billing_month')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Utilities/Electricity/Readings/Index', [
            'readings' => $page->through(fn (ElectricityMeterReading $r) => ElectricityPresenter::reading($r)),
            'filters' => $filters,
            'sites' => ElectricityPresenter::siteFilterOptions(),
            'statuses' => ElectricityReadingStatus::options(),
            'can' => ['create' => $request->user()->can('create', ElectricityMeterReading::class)],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', ElectricityMeterReading::class);

        return $this->form(null);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ElectricityMeterReading::class);

        $data = $this->validated($request);
        $reading = $this->readings->create($data, $data['tod_readings'] ?? []);

        return redirect()->route('utilities.electricity.readings.show', $reading)->with('success', 'Reading saved.');
    }

    public function show(Request $request, ElectricityMeterReading $electricityMeterReading): Response
    {
        Gate::authorize('view', $electricityMeterReading);
        $user = $request->user();
        $electricityMeterReading->load(['site', 'connection', 'meter', 'todReadings', 'bill:id,electricity_meter_reading_id,bill_number,status']);

        return Inertia::render('Utilities/Electricity/Readings/Show', [
            'reading' => ElectricityPresenter::reading($electricityMeterReading),
            'tod_readings' => $electricityMeterReading->todReadings
                ->map(fn (ElectricityMeterTodReading $t) => $t->only(['id', 'slot_code', 'slot_name', 'units']))->all(),
            'bill' => $electricityMeterReading->bill ? [
                'id' => $electricityMeterReading->bill->id,
                'bill_number' => $electricityMeterReading->bill->bill_number,
                'status' => $electricityMeterReading->bill->status->value,
            ] : null,
            'can' => [
                'update' => $user->can('update', $electricityMeterReading),
                'create_bill' => ! $electricityMeterReading->bill
                    && $user->can('create', ElectricityBill::class)
                    && $electricityMeterReading->status === ElectricityReadingStatus::Confirmed,
            ],
        ]);
    }

    public function edit(ElectricityMeterReading $electricityMeterReading): Response
    {
        Gate::authorize('update', $electricityMeterReading);
        $electricityMeterReading->load('todReadings');

        return $this->form($electricityMeterReading);
    }

    public function update(Request $request, ElectricityMeterReading $electricityMeterReading): RedirectResponse
    {
        Gate::authorize('update', $electricityMeterReading);

        $data = $this->validated($request);
        $this->readings->update($electricityMeterReading, $data, $data['tod_readings'] ?? null);

        return redirect()->route('utilities.electricity.readings.show', $electricityMeterReading)->with('success', 'Reading updated.');
    }

    public function confirm(Request $request, ElectricityMeterReading $electricityMeterReading): RedirectResponse
    {
        Gate::authorize('confirm', $electricityMeterReading);

        $this->readings->confirm($electricityMeterReading, $request->user());

        return back()->with('success', 'Reading confirmed.');
    }

    private function form(?ElectricityMeterReading $reading): Response
    {
        $sites = UtilitySite::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (UtilitySite $s) => ['value' => $s->id, 'label' => "{$s->code} — {$s->name}"])->all();
        $connections = ElectricityConnection::query()->where('is_active', true)->orderBy('connection_number')
            ->get(['id', 'utility_site_id', 'connection_number'])
            ->map(fn (ElectricityConnection $c) => [
                'value' => $c->id,
                'label' => $c->connection_number,
                'utility_site_id' => $c->utility_site_id,
            ])->all();
        $meters = ElectricityMeter::query()->where('is_active', true)->orderBy('meter_number')
            ->get(['id', 'electricity_connection_id', 'meter_number', 'multiplication_factor'])
            ->map(function (ElectricityMeter $m) {
                $defaults = $this->readings->defaultOpenings($m);

                return [
                    'value' => $m->id,
                    'label' => $m->meter_number,
                    'electricity_connection_id' => $m->electricity_connection_id,
                    'multiplication_factor' => (string) $m->multiplication_factor,
                    'opening_kwh' => $defaults['opening_kwh'],
                    'opening_kvah' => $defaults['opening_kvah'],
                ];
            })->all();

        $payload = $reading ? [
            ...ElectricityPresenter::reading($reading),
            'tod_readings' => $reading->todReadings->map(fn ($t) => [
                'slot_code' => $t->slot_code,
                'slot_name' => $t->slot_name,
                'opening' => $t->opening !== null ? (string) $t->opening : null,
                'closing' => $t->closing !== null ? (string) $t->closing : null,
                'units' => (string) $t->units,
            ])->all(),
        ] : null;

        return Inertia::render('Utilities/Electricity/Readings/Form', [
            'reading' => $payload,
            'sites' => $sites,
            'connections' => $connections,
            'meters' => $meters,
            'tod_slots' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'utility_site_id' => ['required', new ExistsInCompany(UtilitySite::class)],
            'electricity_connection_id' => ['required', new ExistsInCompany(ElectricityConnection::class)],
            'electricity_meter_id' => ['required', new ExistsInCompany(ElectricityMeter::class)],
            'billing_month' => ['required', 'date'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            // Opening is optional on the form — filled from the previous confirmed closing when omitted.
            'opening_kwh' => ['nullable', 'numeric', 'min:0'],
            'closing_kwh' => ['required', 'numeric', 'min:0'],
            'opening_kvah' => ['nullable', 'numeric', 'min:0'],
            'closing_kvah' => ['nullable', 'numeric', 'min:0'],
            'mdi_reading' => ['nullable', 'numeric', 'min:0'],
            'multiplication_factor' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'tod_readings' => ['nullable', 'array'],
            'tod_readings.*.slot_code' => ['required_with:tod_readings', 'string', 'max:40'],
            'tod_readings.*.slot_name' => ['required_with:tod_readings', 'string', 'max:80'],
            'tod_readings.*.opening' => ['nullable', 'numeric'],
            'tod_readings.*.closing' => ['nullable', 'numeric'],
            'tod_readings.*.units' => ['nullable', 'numeric'],
        ]);
    }
}
