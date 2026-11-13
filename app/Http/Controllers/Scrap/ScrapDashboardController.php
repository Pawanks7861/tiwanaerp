<?php

namespace App\Http\Controllers\Scrap;

use App\Enums\Scrap\ScrapGenerationStatus;
use App\Enums\Scrap\ScrapLedgerTxnType;
use App\Http\Controllers\Controller;
use App\Models\Masters\ScrapType;
use App\Models\Scrap\ScrapGeneration;
use App\Models\Scrap\ScrapLedgerEntry;
use App\Reports\ReportRunner;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ScrapDashboardController extends Controller
{
    public function __construct(private readonly ReportRunner $runner) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAnyCompany', ScrapGeneration::class);

        $user = $request->user();
        $companyId = app(CurrentCompany::class)->require()->id;
        $visible = $this->runner->visibleProjectIds($user);
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $typesCount = ScrapType::query()->active()->count();

        $availableLines = (int) DB::table('scrap_balances')
            ->where('company_id', $companyId)
            ->whereIn('project_id', $visible)
            ->where('quantity', '>', 0)
            ->count();

        $ledger = fn (ScrapLedgerTxnType $type) => (float) DB::table('scrap_ledger_entries')
            ->where('company_id', $companyId)
            ->whereIn('project_id', $visible)
            ->where('transaction_type', $type->value)
            ->whereBetween('transaction_date', [$monthStart, $monthEnd])
            ->selectRaw('COALESCE(SUM(quantity_in), 0) + COALESCE(SUM(quantity_out), 0) as qty')
            ->value('qty');

        $openGenerations = ScrapGeneration::query()
            ->whereIn('project_id', $visible)
            ->whereIn('status', [ScrapGenerationStatus::Draft, ScrapGenerationStatus::Submitted])
            ->count();

        $pendingApproval = ScrapGeneration::query()
            ->whereIn('project_id', $visible)
            ->where('status', ScrapGenerationStatus::Submitted)
            ->count();

        $recent = ScrapGeneration::query()
            ->with('project:id,code,name')
            ->whereIn('project_id', $visible)
            ->latest('id')
            ->limit(8)
            ->get(['id', 'project_id', 'generation_number', 'generation_date', 'status', 'created_at']);

        return Inertia::render('Scrap/Dashboard', [
            'cards' => [
                ['key' => 'types', 'label' => 'Active scrap types', 'value' => $typesCount],
                ['key' => 'available', 'label' => 'Balance lines (qty > 0)', 'value' => $availableLines],
                ['key' => 'generated', 'label' => 'Generated this month', 'value' => $ledger(ScrapLedgerTxnType::GenerationIn)],
                ['key' => 'dispatched', 'label' => 'Dispatched this month', 'value' => $ledger(ScrapLedgerTxnType::GatePassOut)],
                ['key' => 'open', 'label' => 'Open generations', 'value' => $openGenerations],
                ['key' => 'pending', 'label' => 'Awaiting approval', 'value' => $pendingApproval],
            ],
            'recent' => $recent->map(fn ($g) => [
                'id' => $g->id,
                'generation_number' => $g->generation_number,
                'generation_date' => $g->generation_date?->toDateString(),
                'status' => $g->status->value,
                'status_label' => $g->status->label(),
                'project' => $g->project?->only(['id', 'code', 'name']),
                'show_url' => route('projects.scrap.generations.show', [$g->project_id, $g->id]),
            ])->all(),
            'projects' => ScrapPresenter::projectFilterOptions($visible),
        ]);
    }
}
