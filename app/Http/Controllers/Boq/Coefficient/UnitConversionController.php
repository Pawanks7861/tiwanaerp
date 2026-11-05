<?php

namespace App\Http\Controllers\Boq\Coefficient;

use App\Enums\Boq\UnitConversionOperation;
use App\Http\Controllers\Controller;
use App\Models\Boq\UnitConversion;
use App\Models\Masters\Material;
use App\Models\Projects\Project;
use App\Services\Boq\UnitConversionService;
use App\Support\Boq\BoqCompanySettings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UnitConversionController extends Controller
{
    public function __construct(
        private readonly UnitConversionService $conversions,
        private readonly CurrentCompany $company,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(BoqCompanySettings::enabled(), 403);

            return $next($request);
        });
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('boq.manage_unit_conversions'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'in:1,0'],
        ]);

        $page = UnitConversion::query()
            ->with(['material:id,code,name', 'project:id,code,name'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('from_unit', 'like', "%{$s}%")
                    ->orWhere('to_unit', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%");
            }))
            ->when(isset($filters['active']), fn ($q) => $q->where('is_active', $filters['active'] === '1'))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Coefficients/Conversions/Index', [
            'conversions' => $page->through(fn (UnitConversion $c) => [
                'id' => $c->id,
                'from_unit' => $c->from_unit,
                'to_unit' => $c->to_unit,
                'operation' => $c->operation->value,
                'operation_label' => $c->operation->label(),
                'factor' => (string) $c->factor,
                'description' => $c->description,
                'material_id' => $c->material_id,
                'material_label' => $c->material ? trim("{$c->material->code} {$c->material->name}") : null,
                'project_id' => $c->project_id,
                'project_label' => $c->project ? trim("{$c->project->code} {$c->project->name}") : null,
                'is_active' => $c->is_active,
                'effective_from' => optional($c->effective_from)?->format('Y-m-d'),
            ]),
            'filters' => $filters,
            'operations' => UnitConversionOperation::options(),
            'materials' => Material::query()->active()->orderBy('name')->limit(500)
                ->get(['id', 'code', 'name'])
                ->map(fn (Material $m) => ['value' => $m->id, 'label' => trim("{$m->code} {$m->name}")])->all(),
            'projects' => Project::query()->orderBy('name')->limit(200)
                ->get(['id', 'code', 'name'])
                ->map(fn (Project $p) => ['value' => $p->id, 'label' => trim("{$p->code} {$p->name}")])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('boq.manage_unit_conversions'), 403);

        $this->conversions->save($this->company->require()->id, $this->validated($request), null, $request->user());

        return back()->with('success', 'Unit conversion saved.');
    }

    public function update(Request $request, UnitConversion $conversion): RedirectResponse
    {
        abort_unless($request->user()->can('boq.manage_unit_conversions'), 403);

        $this->conversions->save($this->company->require()->id, $this->validated($request), $conversion, $request->user());

        return back()->with('success', 'Unit conversion updated.');
    }

    public function destroy(Request $request, UnitConversion $conversion): RedirectResponse
    {
        abort_unless($request->user()->can('boq.manage_unit_conversions'), 403);

        $conversion->forceFill(['is_active' => false, 'updated_by' => $request->user()->id])->save();

        return back()->with('success', 'Unit conversion deactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'from_unit' => ['required', 'string', 'max:40'],
            'to_unit' => ['required', 'string', 'max:40'],
            'operation' => ['required', Rule::enum(UnitConversionOperation::class)],
            'factor' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string', 'max:255'],
            'material_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'effective_from' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ]);
    }
}
