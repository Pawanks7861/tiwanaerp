<?php

namespace App\Http\Controllers\Quality;

use App\Enums\Quality\InspectionStatus;
use App\Enums\Quality\NcrSeverity;
use App\Enums\Quality\NcrStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Core\AuditPresenter;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Http\Controllers\SiteExecution\SiteExecutionPresenter;
use App\Models\Masters\Subcontractor;
use App\Models\Projects\Project;
use App\Models\Quality\Ncr;
use App\Models\Quality\QualityInspection;
use App\Models\User;
use App\Services\Quality\NcrService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NcrController extends Controller
{
    public function __construct(private readonly NcrService $ncrs) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [Ncr::class, $project]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(NcrStatus::class)],
            'severity' => ['nullable', Rule::enum(NcrSeverity::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->ncrs()
            ->with(['responsibleUser:id,name', 'subcontractor:id,name', 'inspection:id,inspection_number'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('ncr_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('issue', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('location', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('Quality/Ncrs/Index', [
            'project' => ProjectHeader::for($project),
            'ncrs' => $page->through(fn (Ncr $n) => $this->header($n)),
            'filters' => $filters,
            'statuses' => NcrStatus::options(),
            'severities' => NcrSeverity::options(),
            'can' => ['create' => $request->user()->can('create', [Ncr::class, $project])],
        ]);
    }

    public function create(Request $request, Project $project): Response
    {
        Gate::authorize('create', [Ncr::class, $project]);

        $inspectionId = $request->integer('inspection') ?: null;

        return $this->form($project, null, $inspectionId);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [Ncr::class, $project]);

        $data = $request->validate(['quality_inspection_id' => ['nullable', 'integer'], ...$this->detailRules()], [], ['quality_inspection_id' => 'inspection']);
        $ncr = $this->ncrs->create($project, $data);

        return redirect()->route('projects.ncrs.show', [$project, $ncr])->with('success', "NCR {$ncr->ncr_number} raised.");
    }

    public function show(Request $request, Project $project, Ncr $ncr): Response
    {
        Gate::authorize('view', $ncr);
        $user = $request->user();
        $ncr->load(['inspection:id,inspection_number,result', 'responsibleUser:id,name', 'subcontractor:id,name', 'resolver:id,name', 'verifier:id,name', 'closer:id,name', 'creator:id,name']);

        return Inertia::render('Quality/Ncrs/Show', [
            'project' => ProjectHeader::for($project),
            'ncr' => [
                ...$this->header($ncr),
                ...$ncr->only(['issue', 'root_cause', 'corrective_action', 'verification_remarks']),
                'inspection_id' => $ncr->quality_inspection_id,
                'raised_by' => $ncr->creator?->name,
                'raised_at' => $ncr->created_at?->toIso8601String(),
                'resolved_by' => $ncr->resolver?->name,
                'resolved_at' => $ncr->resolved_at?->toIso8601String(),
                'verified_by' => $ncr->verifier?->name,
                'verified_at' => $ncr->verified_at?->toIso8601String(),
                'closed_by' => $ncr->closer?->name,
                'closed_at' => $ncr->closed_at?->toIso8601String(),
            ],
            'attachments' => ProcurementPresenter::attachments($ncr),
            'audit' => AuditPresenter::trail([$ncr]),
            'can' => $this->abilities($user, $ncr),
        ]);
    }

    public function edit(Project $project, Ncr $ncr): Response
    {
        Gate::authorize('update', $ncr);

        return $this->form($project, $ncr, null);
    }

    public function update(Request $request, Project $project, Ncr $ncr): RedirectResponse
    {
        Gate::authorize('update', $ncr);

        $this->ncrs->update($ncr, $request->validate($this->detailRules()));

        return redirect()->route('projects.ncrs.show', [$project, $ncr])->with('success', 'NCR updated.');
    }

    public function destroy(Project $project, Ncr $ncr): RedirectResponse
    {
        Gate::authorize('delete', $ncr);

        $this->ncrs->delete($ncr);

        return redirect()->route('projects.ncrs.index', $project)->with('success', "NCR {$ncr->ncr_number} deleted.");
    }

    public function start(Project $project, Ncr $ncr): RedirectResponse
    {
        Gate::authorize('start', $ncr);

        $this->ncrs->start($ncr);

        return back()->with('success', 'NCR is now in progress.');
    }

    public function resolve(Request $request, Project $project, Ncr $ncr): RedirectResponse
    {
        Gate::authorize('resolve', $ncr);

        $data = $request->validate([
            'root_cause' => ['required', 'string', 'max:2000'],
            'corrective_action' => ['required', 'string', 'max:2000'],
        ]);
        $this->ncrs->resolve($ncr, $data, $request->user());

        return back()->with('success', 'NCR resolved. Someone else must now verify the corrective action.');
    }

    public function verify(Request $request, Project $project, Ncr $ncr): RedirectResponse
    {
        Gate::authorize('verify', $ncr);

        $remarks = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']])['remarks'] ?? null;
        $this->ncrs->verify($ncr, $remarks, $request->user());

        return back()->with('success', 'Corrective action verified.');
    }

    public function reopen(Request $request, Project $project, Ncr $ncr): RedirectResponse
    {
        Gate::authorize('reopen', $ncr);

        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason'];
        $this->ncrs->reopen($ncr, $reason, $request->user());

        return back()->with('success', 'Resolution not accepted; the NCR is back in progress.');
    }

    public function close(Request $request, Project $project, Ncr $ncr): RedirectResponse
    {
        Gate::authorize('close', $ncr);

        $this->ncrs->close($ncr, $request->user());

        return back()->with('success', "NCR {$ncr->ncr_number} closed.");
    }

    /**
     * @return array<string, mixed>
     */
    private function detailRules(): array
    {
        return [
            'issue' => ['required', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', Rule::enum(NcrSeverity::class)],
            'responsible_user_id' => ['nullable', 'integer'],
            'subcontractor_id' => ['nullable', 'integer'],
            'target_date' => ['nullable', 'date'],
        ];
    }

    private function form(Project $project, ?Ncr $ncr, ?int $inspectionId): Response
    {
        return Inertia::render('Quality/Ncrs/Form', [
            'project' => ProjectHeader::for($project),
            'ncr' => $ncr ? [
                'id' => $ncr->id,
                'ncr_number' => $ncr->ncr_number,
                'inspection' => $ncr->inspection?->inspection_number,
                ...$ncr->only(['issue', 'location', 'responsible_user_id', 'subcontractor_id']),
                'severity' => $ncr->severity->value,
                'target_date' => $ncr->target_date?->toDateString(),
            ] : null,
            'inspections' => $ncr ? [] : QualityInspection::query()->where('project_id', $project->id)
                ->where('status', InspectionStatus::Completed)->whereIn('result', ['failed', 'conditional'])
                ->latest('id')->limit(200)->get(['id', 'inspection_number', 'result', 'location'])
                ->map(fn (QualityInspection $i) => ['value' => $i->id, 'label' => $i->inspection_number, 'description' => trim($i->result->label().' · '.($i->location ?? ''), ' ·'), 'location' => $i->location])->all(),
            'defaultInspectionId' => $inspectionId,
            'severities' => NcrSeverity::options(),
            'members' => SiteExecutionPresenter::memberOptions($project),
            'subcontractors' => Subcontractor::query()->active()->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Subcontractor $s) => ['value' => $s->id, 'label' => $s->name, 'description' => $s->code])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Ncr $ncr): array
    {
        return [
            'id' => $ncr->id,
            'ncr_number' => $ncr->ncr_number,
            'status' => $ncr->status->value,
            'status_label' => $ncr->status->label(),
            'severity' => $ncr->severity->value,
            'severity_label' => $ncr->severity->label(),
            'issue_excerpt' => str($ncr->issue)->limit(90)->toString(),
            'location' => $ncr->location,
            'inspection' => $ncr->inspection?->inspection_number,
            'responsible' => collect([$ncr->responsibleUser?->name, $ncr->subcontractor?->name])->filter()->implode(' / ') ?: null,
            'target_date' => $ncr->target_date?->toDateString(),
            'overdue' => $ncr->target_date !== null && $ncr->target_date->isPast() && ! in_array($ncr->status, [NcrStatus::Verified, NcrStatus::Closed], true)
                && ! $ncr->target_date->isToday(),
        ];
    }

    /**
     * Permission AND state (platform super admins pass every policy check).
     *
     * @return array<string, bool>
     */
    private function abilities(User $user, Ncr $ncr): array
    {
        $status = $ncr->status;

        return [
            'update' => $status->isEditable() && $user->can('update', $ncr),
            'delete' => $status === NcrStatus::Open && $user->can('delete', $ncr),
            'start' => $status === NcrStatus::Open && $user->can('start', $ncr),
            'resolve' => $status === NcrStatus::InProgress && $user->can('resolve', $ncr),
            'verify' => $status === NcrStatus::Resolved && (int) $ncr->resolved_by !== (int) $user->id && $user->can('verify', $ncr),
            'reopen' => $status === NcrStatus::Resolved && $user->can('reopen', $ncr),
            'close' => $status === NcrStatus::Verified && $user->can('close', $ncr),
            'attach' => $status->isEditable() && $user->can('update', $ncr),
        ];
    }
}
