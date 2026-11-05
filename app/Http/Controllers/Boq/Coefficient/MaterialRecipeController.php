<?php

namespace App\Http\Controllers\Boq\Coefficient;

use App\Enums\Boq\MaterialRecipeStatus;
use App\Http\Controllers\Controller;
use App\Models\Boq\MaterialRecipeTemplate;
use App\Models\Masters\Material;
use App\Models\Projects\Project;
use App\Services\Boq\MaterialRecipeService;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaterialRecipeController extends Controller
{
    public function __construct(
        private readonly MaterialRecipeService $recipes,
        private readonly CurrentCompany $company,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(BoqCompanySettings::enabled(), 403);

            return $next($request);
        });
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('boq.manage_recipes'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(MaterialRecipeStatus::class)],
        ]);

        $page = MaterialRecipeTemplate::query()
            ->withCount('lines')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%");
            }))
            ->when($filters['status'] ?? null, fn ($q, $st) => $q->where('status', $st))
            ->orderBy('code')
            ->orderByDesc('version')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Coefficients/Recipes/Index', [
            'recipes' => $page->through(fn (MaterialRecipeTemplate $r) => [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'version' => $r->version,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'line_count' => $r->lines_count,
                'project_id' => $r->project_id,
            ]),
            'filters' => $filters,
            'statuses' => MaterialRecipeStatus::options(),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('boq.manage_recipes'), 403);

        return $this->editForm(null);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('boq.manage_recipes'), 403);

        $data = $this->validated($request);
        $recipe = $this->recipes->createDraft(
            $this->company->require()->id,
            $data['header'],
            $data['lines'],
            $request->user(),
        );

        return redirect()
            ->route('coefficients.recipes.show', $recipe)
            ->with('success', 'Material recipe draft created.');
    }

    public function show(Request $request, MaterialRecipeTemplate $recipe): Response
    {
        abort_unless($request->user()->can('boq.manage_recipes'), 403);

        return $this->editForm($recipe);
    }

    public function update(Request $request, MaterialRecipeTemplate $recipe): RedirectResponse
    {
        abort_unless($request->user()->can('boq.manage_recipes'), 403);

        $data = $this->validated($request, $recipe);
        $this->recipes->updateDraft($recipe, $data['header'], $data['lines'], $request->user());

        return back()->with('success', 'Recipe saved.');
    }

    public function activate(Request $request, MaterialRecipeTemplate $recipe): RedirectResponse
    {
        abort_unless($request->user()->can('boq.manage_recipes'), 403);

        $this->recipes->activate($recipe, $request->user());

        return back()->with('success', 'Recipe activated. Other versions of this code are now inactive.');
    }

    public function newVersion(Request $request, MaterialRecipeTemplate $recipe): RedirectResponse
    {
        abort_unless($request->user()->can('boq.manage_recipes'), 403);

        $draft = $this->recipes->createNewVersion($recipe, $request->user());

        return redirect()
            ->route('coefficients.recipes.show', $draft)
            ->with('success', 'New draft version created.');
    }

    private function editForm(?MaterialRecipeTemplate $recipe): Response
    {
        $recipe?->load(['lines.material:id,code,name,unit_id', 'lines.material.unit:id,symbol', 'project:id,name,code']);

        $companyId = $this->company->require()->id;

        return Inertia::render('Coefficients/Recipes/Edit', [
            'recipe' => $recipe ? [
                'id' => $recipe->id,
                'code' => $recipe->code,
                'name' => $recipe->name,
                'description' => $recipe->description,
                'version' => $recipe->version,
                'status' => $recipe->status->value,
                'status_label' => $recipe->status->label(),
                'notes' => $recipe->notes,
                'project_id' => $recipe->project_id,
                'effective_from' => optional($recipe->effective_from)?->format('Y-m-d'),
                'effective_to' => optional($recipe->effective_to)?->format('Y-m-d'),
                'lines' => $recipe->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'material_id' => $line->material_id,
                    'material_label' => $line->material
                        ? trim("{$line->material->code} {$line->material->name}")
                        : null,
                    'consumption_qty' => (string) $line->consumption_qty,
                    'consumption_unit' => $line->consumption_unit,
                    'wastage_percent' => (string) $line->wastage_percent,
                    'notes' => $line->notes,
                ])->all(),
            ] : null,
            'materials' => Material::query()->active()->with('unit:id,symbol')->orderBy('name')->limit(2000)
                ->get(['id', 'code', 'name', 'unit_id'])
                ->map(fn (Material $m) => [
                    'value' => $m->id,
                    'label' => trim("{$m->code} {$m->name}"),
                    'unit' => $m->unit?->symbol,
                ])->all(),
            'projects' => Project::query()->orderBy('name')->limit(500)
                ->get(['id', 'name', 'code'])
                ->map(fn (Project $p) => ['value' => $p->id, 'label' => trim("{$p->code} {$p->name}")])->all(),
            'can' => [
                'update' => ! $recipe || $recipe->status === MaterialRecipeStatus::Draft,
                'activate' => $recipe && $recipe->status === MaterialRecipeStatus::Draft,
                'new_version' => $recipe && $recipe->status !== MaterialRecipeStatus::Draft,
            ],
        ]);
    }

    /**
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    private function validated(Request $request, ?MaterialRecipeTemplate $recipe = null): array
    {
        $data = $request->validate([
            'code' => [$recipe ? 'prohibited' : 'required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'project_id' => ['nullable', 'integer'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'lines' => ['present', 'array', 'max:200'],
            'lines.*.material_id' => ['required', 'integer'],
            'lines.*.consumption_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.consumption_unit' => ['required', 'string', 'max:40'],
            'lines.*.wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $header = [
            'code' => $recipe?->code ?? $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'notes' => $data['notes'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
        ];

        return ['header' => $header, 'lines' => $data['lines']];
    }
}
