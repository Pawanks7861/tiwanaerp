<?php

namespace App\Http\Controllers\Boq\Coefficient;

use App\Enums\Boq\MaterialRecipeStatus;
use App\Http\Controllers\Controller;
use App\Models\Boq\SteelUnitWeightProfile;
use App\Models\Projects\Project;
use App\Services\Boq\SteelUnitWeightProfileService;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SteelUnitWeightProfileController extends Controller
{
    public function __construct(
        private readonly SteelUnitWeightProfileService $profiles,
        private readonly CurrentCompany $company,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(BoqCompanySettings::enabled(), 403);

            return $next($request);
        });
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('boq.manage_steel_profiles'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(MaterialRecipeStatus::class)],
        ]);

        $page = SteelUnitWeightProfile::query()
            ->withCount('lines')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($filters['status'] ?? null, fn ($q, $st) => $q->where('status', $st))
            ->orderBy('name')
            ->orderByDesc('version')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Coefficients/SteelProfiles/Index', [
            'profiles' => $page->through(fn (SteelUnitWeightProfile $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'version' => $p->version,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'line_count' => $p->lines_count,
            ]),
            'filters' => $filters,
            'statuses' => MaterialRecipeStatus::options(),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('boq.manage_steel_profiles'), 403);

        return $this->editForm(null);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('boq.manage_steel_profiles'), 403);

        $data = $this->validated($request);
        $profile = $this->profiles->save(
            $this->company->require()->id,
            $data['header'],
            $data['lines'],
            $request->user(),
        );

        return redirect()
            ->route('coefficients.steel-profiles.show', $profile)
            ->with('success', 'Steel unit-weight profile draft created.');
    }

    public function show(Request $request, SteelUnitWeightProfile $steelProfile): Response
    {
        abort_unless($request->user()->can('boq.manage_steel_profiles'), 403);

        return $this->editForm($steelProfile);
    }

    public function update(Request $request, SteelUnitWeightProfile $steelProfile): RedirectResponse
    {
        abort_unless($request->user()->can('boq.manage_steel_profiles'), 403);

        $data = $this->validated($request);
        $this->profiles->save(
            $this->company->require()->id,
            $data['header'],
            $data['lines'],
            $request->user(),
            $steelProfile,
        );

        return back()->with('success', 'Profile saved.');
    }

    public function activate(Request $request, SteelUnitWeightProfile $steelProfile): RedirectResponse
    {
        abort_unless($request->user()->can('boq.manage_steel_profiles'), 403);

        $this->profiles->activate($steelProfile, $request->user());

        return back()->with('success', 'Profile activated.');
    }

    private function editForm(?SteelUnitWeightProfile $profile): Response
    {
        $profile?->load('lines');

        return Inertia::render('Coefficients/SteelProfiles/Edit', [
            'profile' => $profile ? [
                'id' => $profile->id,
                'name' => $profile->name,
                'version' => $profile->version,
                'status' => $profile->status->value,
                'status_label' => $profile->status->label(),
                'notes' => $profile->notes,
                'project_id' => $profile->project_id,
                'lines' => $profile->lines->map(fn ($line) => [
                    'diameter_mm' => $line->diameter_mm,
                    'weight_kg_per_m' => (string) $line->weight_kg_per_m,
                ])->all(),
            ] : null,
            'projects' => Project::query()->orderBy('name')->limit(200)
                ->get(['id', 'code', 'name'])
                ->map(fn (Project $p) => ['value' => $p->id, 'label' => trim("{$p->code} {$p->name}")])->all(),
            'can' => [
                'update' => ! $profile || $profile->status === MaterialRecipeStatus::Draft,
                'activate' => $profile && $profile->status === MaterialRecipeStatus::Draft,
            ],
        ]);
    }

    /**
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'project_id' => ['nullable', 'integer'],
            'lines' => ['present', 'array', 'max:64'],
            'lines.*.diameter_mm' => ['required', 'integer', 'min:1', 'max:64'],
            'lines.*.weight_kg_per_m' => ['required', 'numeric', 'min:0'],
        ]);

        return [
            'header' => [
                'name' => $data['name'],
                'notes' => $data['notes'] ?? null,
                'project_id' => $data['project_id'] ?? null,
            ],
            'lines' => $data['lines'],
        ];
    }
}
