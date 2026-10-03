<?php

namespace App\Http\Controllers\Finance;

use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\ProcurementPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\RetentionRelease;
use App\Models\Projects\Project;
use App\Models\Subcontract\SubcontractorBill;
use App\Services\Finance\RetentionReleaseService;
use App\Support\Math\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RetentionController extends Controller
{
    public function __construct(private readonly RetentionReleaseService $releases) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [RetentionRelease::class, $project]);
        $user = $request->user();

        $bills = collect();
        if ($user->can('billing.view')) {
            $bills = $bills->concat($project->clientInvoices()->whereIn('status', ClientInvoiceStatus::certifiedStates())
                ->where('retention_amount', '>', 0)->with('client:id,company_name')->orderBy('ra_sequence')->get()
                ->map(fn (ClientInvoice $i) => $this->billRow($i, 'client_invoice', $i->displayNumber(), $i->client?->company_name)));
        }
        if ($user->can('subcontract.view')) {
            $bills = $bills->concat($project->subcontractorBills()->whereIn('status', SubcontractorBillStatus::certifiedStates())
                ->where('retention_amount', '>', 0)->with('subcontractor:id,name')->orderBy('id')->get()
                ->map(fn (SubcontractorBill $b) => $this->billRow($b, 'subcontractor_bill', $b->bill_number, $b->subcontractor?->name)));
        }
        $visibleTypes = array_values(array_filter([
            $user->can('billing.view') ? 'client_invoice' : null,
            $user->can('subcontract.view') ? 'subcontractor_bill' : null,
        ]));

        $releases = $project->retentionReleases()->whereIn('releasable_type', $visibleTypes)
            ->with(['releasable', 'creator:id,name', 'approver:id,name'])->latest('id')->get();

        return Inertia::render('Finance/Retention/Index', [
            'project' => ProjectHeader::for($project),
            'bills' => $bills->values()->all(),
            'releases' => $releases->map(fn (RetentionRelease $r) => [
                'id' => $r->id,
                'release_number' => $r->release_number,
                'release_date' => $r->release_date?->toDateString(),
                'type' => $r->releasable_type,
                'bill' => $r->releasable instanceof ClientInvoice ? $r->releasable->displayNumber() : $r->releasable?->bill_number,
                'amount' => $r->amount,
                'remarks' => $r->remarks,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'created_by' => $r->creator?->name,
                'approved_by' => $r->approver?->name,
                'approval' => ProcurementPresenter::approval($r, $user),
                'can' => [
                    'update' => $r->isEditable() && $user->can('update', $r),
                    'delete' => $r->isEditable() && $user->can('delete', $r),
                    'submit' => $r->isEditable() && $user->can('submit', $r),
                ],
            ])->all(),
            'can' => ['create' => $user->can('create', [RetentionRelease::class, $project])],
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [RetentionRelease::class, $project]);

        $data = $request->validate([
            'releasable_type' => ['required', 'in:client_invoice,subcontractor_bill'],
            'releasable_id' => ['required', 'integer'],
            ...$this->rules(),
        ]);
        $release = $this->releases->create($project, $data, $request->user());

        return back()->with('success', "Retention release {$release->release_number} saved as draft.");
    }

    public function update(Request $request, Project $project, RetentionRelease $retentionRelease): RedirectResponse
    {
        Gate::authorize('update', $retentionRelease);

        $this->releases->update($retentionRelease, $request->validate($this->rules()), $request->user());

        return back()->with('success', 'Retention release updated.');
    }

    public function destroy(Project $project, RetentionRelease $retentionRelease): RedirectResponse
    {
        Gate::authorize('delete', $retentionRelease);

        $this->releases->delete($retentionRelease);

        return back()->with('success', "Retention release {$retentionRelease->release_number} deleted.");
    }

    public function submit(Request $request, Project $project, RetentionRelease $retentionRelease): RedirectResponse
    {
        Gate::authorize('submit', $retentionRelease);

        $this->releases->submit($retentionRelease, $request->user());

        return back()->with('success', 'Retention release submitted for approval.');
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'release_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function billRow(ClientInvoice|SubcontractorBill $bill, string $type, ?string $number, ?string $party): array
    {
        $balance = $this->releases->balance($bill);
        $pending = $this->releases->balance($bill, includePending: true);

        return [
            'type' => $type,
            'type_label' => $type === 'client_invoice' ? 'Client RA bill' : 'Subcontractor bill',
            'id' => $bill->id,
            'number' => $number,
            'party' => $party,
            'held' => $bill->retention_amount,
            'released' => Decimal::of($bill->retention_amount)->minus($balance)->toMoney(),
            'balance' => $balance->toMoney(),
            'releasable' => $pending->toMoney(),
        ];
    }
}
