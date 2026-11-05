<?php

namespace App\Http\Controllers\Boq\Coefficient;

use App\Http\Controllers\Controller;
use App\Models\Boq\CostingRateLibraryEntry;
use App\Models\Projects\Project;
use App\Services\Boq\CostingRateLibraryService;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CostingRateLibraryController extends Controller
{
    public function __construct(
        private readonly CostingRateLibraryService $library,
        private readonly CurrentCompany $company,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(BoqCompanySettings::enabled(), 403);

            return $next($request);
        });
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('boq.manage_rate_library'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'entry_type' => ['nullable', 'string', 'max:40'],
            'active' => ['nullable', 'in:1,0'],
        ]);

        $page = CostingRateLibraryEntry::query()
            ->with('project:id,code,name')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('description', 'like', "%{$s}%"))
            ->when($filters['entry_type'] ?? null, fn ($q, $t) => $q->where('entry_type', $t))
            ->when(isset($filters['active']), fn ($q) => $q->where('is_active', $filters['active'] === '1'))
            ->orderBy('entry_type')
            ->orderBy('description')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Coefficients/RateLibrary/Index', [
            'entries' => $page->through(fn (CostingRateLibraryEntry $e) => [
                'id' => $e->id,
                'entry_type' => $e->entry_type,
                'description' => $e->description,
                'unit' => $e->unit,
                'rate' => (string) $e->rate,
                'source' => $e->source,
                'notes' => $e->notes,
                'project_id' => $e->project_id,
                'project_label' => $e->project ? trim("{$e->project->code} {$e->project->name}") : null,
                'is_active' => $e->is_active,
                'effective_from' => optional($e->effective_from)?->format('Y-m-d'),
            ]),
            'filters' => $filters,
            'entry_types' => [
                ['value' => 'material', 'label' => 'Material'],
                ['value' => 'labour', 'label' => 'Labour'],
                ['value' => 'equipment', 'label' => 'Equipment'],
                ['value' => 'subcontract', 'label' => 'Subcontract'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            'projects' => Project::query()->orderBy('name')->limit(200)
                ->get(['id', 'code', 'name'])
                ->map(fn (Project $p) => ['value' => $p->id, 'label' => trim("{$p->code} {$p->name}")])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('boq.manage_rate_library'), 403);

        $this->library->save($this->company->require()->id, $this->validated($request), null, $request->user());

        return back()->with('success', 'Rate library entry saved.');
    }

    public function update(Request $request, CostingRateLibraryEntry $entry): RedirectResponse
    {
        abort_unless($request->user()->can('boq.manage_rate_library'), 403);

        $this->library->save($this->company->require()->id, $this->validated($request), $entry, $request->user());

        return back()->with('success', 'Rate library entry updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'entry_type' => ['required', Rule::in(['material', 'labour', 'equipment', 'subcontract', 'other'])],
            'description' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:40'],
            'rate' => ['required', 'numeric', 'min:0'],
            'effective_from' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'project_id' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
        ]);
    }
}
