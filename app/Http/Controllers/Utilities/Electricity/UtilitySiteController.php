<?php

namespace App\Http\Controllers\Utilities\Electricity;

use App\Enums\IndianState;
use App\Http\Controllers\Controller;
use App\Models\Utilities\UtilitySite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UtilitySiteController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', UtilitySite::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', Rule::in(['all', '1', '0'])],
        ]);

        $page = UtilitySite::query()
            ->withCount('connections')
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('code', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('name', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when(($filters['active'] ?? 'all') === '1', fn ($q) => $q->where('is_active', true))
            ->when(($filters['active'] ?? 'all') === '0', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Utilities/Electricity/Sites/Index', [
            'sites' => $page->through(fn (UtilitySite $s) => ElectricityPresenter::site($s, true)),
            'filters' => $filters,
            'can' => [
                'create' => $request->user()->can('create', UtilitySite::class),
                'update' => $request->user()->can('utilities.electricity.manage_sites'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', UtilitySite::class);

        return $this->form(null);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', UtilitySite::class);

        $data = $this->validated($request);
        UtilitySite::query()->create($data);

        return redirect()->route('utilities.electricity.sites.index')->with('success', 'Utility site created.');
    }

    public function edit(UtilitySite $utilitySite): Response
    {
        Gate::authorize('update', $utilitySite);

        return $this->form($utilitySite);
    }

    public function update(Request $request, UtilitySite $utilitySite): RedirectResponse
    {
        Gate::authorize('update', $utilitySite);

        $utilitySite->update($this->validated($request, $utilitySite));

        return redirect()->route('utilities.electricity.sites.index')->with('success', 'Site updated.');
    }

    private function form(?UtilitySite $site): Response
    {
        return Inertia::render('Utilities/Electricity/Sites/Form', [
            'site' => $site ? ElectricityPresenter::site($site) : null,
            'states' => IndianState::options(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?UtilitySite $site = null): array
    {
        return $request->validate([
            'code' => [
                'required',
                'string',
                'max:40',
                Rule::unique('utility_sites', 'code')->ignore($site?->id),
            ],
            'name' => ['required', 'string', 'max:200'],
            'state_code' => ['nullable', Rule::enum(IndianState::class)],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
