<?php

namespace App\Http\Controllers\Scrap;

use App\Enums\Scrap\ScrapLedgerTxnType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Projects\Project;
use App\Models\Scrap\ScrapGeneration;
use App\Models\Scrap\ScrapLedgerEntry;
use App\Reports\ReportRunner;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ScrapLedgerController extends Controller
{
    public function __construct(private readonly ReportRunner $runner) {}

    public function companyIndex(Request $request): Response
    {
        Gate::authorize('viewAnyCompany', ScrapGeneration::class);

        return $this->render($request, null);
    }

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [ScrapGeneration::class, $project]);

        return $this->render($request, $project);
    }

    private function render(Request $request, ?Project $project): Response
    {
        $user = $request->user();
        $companyId = app(CurrentCompany::class)->require()->id;
        $visible = $project ? [(int) $project->id] : $this->runner->visibleProjectIds($user);

        $filters = $request->validate([
            'project' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(ScrapLedgerTxnType::class)],
            'scrap_type' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $effectiveProject = $project?->id ?? ($filters['project'] ?? null);

        $page = ScrapLedgerEntry::query()
            ->with(['project:id,code,name', 'scrapType:id,name,code', 'unit:id,symbol', 'creator:id,name'])
            ->where('company_id', $companyId)
            ->whereIn('project_id', $visible)
            ->when($effectiveProject, fn ($q, $id) => $q->where('project_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('transaction_type', $type))
            ->when($filters['scrap_type'] ?? null, fn ($q, $id) => $q->where('scrap_type_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('transaction_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('transaction_date', '<=', $d))
            ->latest('transaction_date')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Scrap/Ledger/Index', [
            'scope' => $project ? 'project' : 'company',
            'project' => $project ? ProjectHeader::for($project) : null,
            'entries' => $page->through(fn (ScrapLedgerEntry $e) => [
                'id' => $e->id,
                'transaction_date' => $e->transaction_date?->toDateString(),
                'type' => $e->transaction_type->value,
                'type_label' => $e->transaction_type->label(),
                'project' => $e->project?->only(['id', 'code', 'name']),
                'scrap_type' => $e->scrapType?->name,
                'unit' => $e->unit?->symbol,
                'quantity_in' => $e->quantity_in,
                'quantity_out' => $e->quantity_out,
                'reference_number' => $e->reference_number,
                'remarks' => $e->remarks,
                'created_by' => $e->creator?->name,
            ]),
            'filters' => $filters,
            'types' => ScrapLedgerTxnType::options(),
            'scrapTypes' => ScrapPresenter::typeOptions(),
            'projects' => $project ? [] : ScrapPresenter::projectFilterOptions($visible),
        ]);
    }
}
