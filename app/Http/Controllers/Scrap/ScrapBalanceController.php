<?php

namespace App\Http\Controllers\Scrap;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Masters\ScrapCategory;
use App\Models\Projects\Project;
use App\Models\Scrap\ScrapBalance;
use App\Models\Scrap\ScrapGeneration;
use App\Models\Scrap\ScrapLedgerEntry;
use App\Reports\ReportRunner;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ScrapBalanceController extends Controller
{
    public function __construct(private readonly ReportRunner $runner) {}

    public function companyIndex(Request $request): Response
    {
        Gate::authorize('viewAnyCompany', ScrapGeneration::class);

        return $this->renderIndex($request, null);
    }

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [ScrapGeneration::class, $project]);

        return $this->renderIndex($request, $project);
    }

    public function show(Request $request, Project $project, ScrapBalance $scrapBalance): Response
    {
        abort_unless((int) $scrapBalance->project_id === (int) $project->id, 404);
        Gate::authorize('viewAny', [ScrapGeneration::class, $project]);

        $ledger = ScrapLedgerEntry::query()
            ->where('project_id', $project->id)
            ->where('scrap_type_id', $scrapBalance->scrap_type_id)
            ->where('unit_id', $scrapBalance->unit_id)
            ->with('creator:id,name')
            ->latest('transaction_date')
            ->latest('id')
            ->limit(40)
            ->get();

        $generations = ScrapGeneration::query()
            ->where('project_id', $project->id)
            ->where('status', 'approved')
            ->whereHas('items', fn ($q) => $q->where('scrap_type_id', $scrapBalance->scrap_type_id))
            ->latest('generation_date')
            ->limit(10)
            ->get(['id', 'generation_number', 'generation_date', 'status']);

        return Inertia::render('Scrap/Balances/Show', [
            'project' => ProjectHeader::for($project),
            'balance' => ScrapPresenter::balanceRow($scrapBalance),
            'ledger' => $ledger->map(fn ($e) => [
                'transaction_date' => $e->transaction_date?->toDateString(),
                'type' => $e->transaction_type->value,
                'type_label' => $e->transaction_type->label(),
                'quantity_in' => $e->quantity_in,
                'quantity_out' => $e->quantity_out,
                'reference_number' => $e->reference_number,
                'remarks' => $e->remarks,
                'created_by' => $e->creator?->name,
            ])->all(),
            'generations' => $generations->map(fn ($g) => [
                'id' => $g->id,
                'generation_number' => $g->generation_number,
                'generation_date' => $g->generation_date?->toDateString(),
                'show_url' => route('projects.scrap.generations.show', [$project->id, $g->id]),
            ])->all(),
        ]);
    }

    private function renderIndex(Request $request, ?Project $project): Response
    {
        $user = $request->user();
        $companyId = app(CurrentCompany::class)->require()->id;
        $visible = $project ? [(int) $project->id] : $this->runner->visibleProjectIds($user);

        $filters = $request->validate([
            'project' => ['nullable', 'integer'],
            'category' => ['nullable', 'integer'],
            'type' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $effectiveProject = $project?->id ?? ($filters['project'] ?? null);

        $page = ScrapBalance::query()
            ->with(['project:id,code,name', 'scrapType:id,name,code,scrap_category_id', 'scrapType.category:id,name', 'unit:id,symbol'])
            ->where('company_id', $companyId)
            ->whereIn('project_id', $visible)
            ->when($effectiveProject, fn ($q, $id) => $q->where('project_id', $id))
            ->when($filters['category'] ?? null, fn ($q, $id) => $q->whereHas('scrapType', fn ($t) => $t->where('scrap_category_id', $id)))
            ->when($filters['type'] ?? null, fn ($q, $id) => $q->where('scrap_type_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->whereHas('scrapType', fn ($t) => $t
                ->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('code', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->where('quantity', '>', 0)
            ->orderBy('project_id')
            ->orderBy('scrap_type_id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Scrap/Balances/Index', [
            'scope' => $project ? 'project' : 'company',
            'project' => $project ? ProjectHeader::for($project) : null,
            'balances' => $page->through(fn (ScrapBalance $b) => [
                ...ScrapPresenter::balanceRow($b),
                'show_url' => route('projects.scrap.balances.show', [$b->project_id, $b->id]),
            ]),
            'filters' => $filters,
            'categories' => ScrapCategory::query()->active()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all(),
            'scrapTypes' => ScrapPresenter::typeOptions(),
            'projects' => $project ? [] : ScrapPresenter::projectFilterOptions($visible),
        ]);
    }
}
