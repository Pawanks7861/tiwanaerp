<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Enums\Finance\ClientInvoiceStatus;
use App\Enums\Subcontract\SubcontractorBillStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\FinancePresenter;
use App\Http\Resources\RetentionReleaseResource;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\RetentionRelease;
use App\Models\Projects\Project;
use App\Models\Subcontract\SubcontractorBill;
use App\Services\Finance\RetentionReleaseService;
use App\Support\Math\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class RetentionReleaseApiController extends Controller
{
    public function __construct(private readonly RetentionReleaseService $releases) {}

    public function eligibleBalances(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [RetentionRelease::class, $project]);
        $user = $request->user();
        $bills = collect();

        if (FinancePresenter::seesBilling($user)) {
            $bills = $bills->concat(
                $project->clientInvoices()->whereIn('status', ClientInvoiceStatus::certifiedStates())
                    ->where('retention_amount', '>', 0)->with('client:id,company_name')->orderBy('ra_sequence')->get()
                    ->map(fn (ClientInvoice $i) => $this->billRow($i, 'client_invoice', $i->displayNumber(), $i->client?->company_name))
            );
        }
        if ($user->can('subcontract.view')) {
            $bills = $bills->concat(
                $project->subcontractorBills()->whereIn('status', SubcontractorBillStatus::certifiedStates())
                    ->where('retention_amount', '>', 0)->with('subcontractor:id,name')->orderBy('id')->get()
                    ->map(fn (SubcontractorBill $b) => $this->billRow($b, 'subcontractor_bill', $b->bill_number, $b->subcontractor?->name))
            );
        }

        return response()->json(['data' => $bills->values()]);
    }

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [RetentionRelease::class, $project]);
        $user = $request->user();
        $visibleTypes = array_values(array_filter([
            FinancePresenter::seesBilling($user) ? ClientInvoice::class : null,
            $user->can('subcontract.view') ? SubcontractorBill::class : null,
        ]));

        $page = $project->retentionReleases()
            ->whereIn('releasable_type', $visibleTypes)
            ->with(['releasable', 'creator:id,name'])
            ->latest('id')
            ->paginate((int) ($request->integer('per_page') ?: 25))
            ->withQueryString();

        return RetentionReleaseResource::collection($page);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [RetentionRelease::class, $project]);
        $data = $request->validate([
            'releasable_type' => ['required', 'in:client_invoice,subcontractor_bill'],
            'releasable_id' => ['required', 'integer'],
            ...$this->bodyRules(),
        ]);
        $release = $this->releases->create($project, $data, $request->user());

        return (new RetentionReleaseResource($this->detail($release)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project, RetentionRelease $retentionRelease): RetentionReleaseResource
    {
        $this->assertOnProject($project, $retentionRelease);
        Gate::authorize('view', $retentionRelease);

        return new RetentionReleaseResource($this->detail($retentionRelease));
    }

    public function update(Request $request, Project $project, RetentionRelease $retentionRelease): RetentionReleaseResource
    {
        $this->assertOnProject($project, $retentionRelease);
        Gate::authorize('update', $retentionRelease);
        $this->releases->update($retentionRelease, $request->validate($this->bodyRules()), $request->user());

        return new RetentionReleaseResource($this->detail($retentionRelease->fresh()));
    }

    public function destroy(Project $project, RetentionRelease $retentionRelease): JsonResponse
    {
        $this->assertOnProject($project, $retentionRelease);
        Gate::authorize('delete', $retentionRelease);
        $this->releases->delete($retentionRelease);

        return response()->json(['message' => 'Retention release deleted.']);
    }

    public function submit(Request $request, Project $project, RetentionRelease $retentionRelease): RetentionReleaseResource
    {
        $this->assertOnProject($project, $retentionRelease);
        Gate::authorize('submit', $retentionRelease);
        $this->releases->submit($retentionRelease, $request->user());

        return new RetentionReleaseResource($this->detail($retentionRelease->fresh()));
    }

    /**
     * @return array<string, list<string>>
     */
    private function bodyRules(): array
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
            'id' => $bill->id,
            'number' => $number,
            'party' => $party,
            'held' => $bill->retention_amount,
            'released' => Decimal::of($bill->retention_amount)->minus($balance)->toMoney(),
            'balance' => $balance->toMoney(),
            'releasable' => $pending->toMoney(),
        ];
    }

    private function detail(RetentionRelease $release): RetentionRelease
    {
        return $release->load(['releasable', 'latestApprovalRequest.submitter:id,name']);
    }

    private function assertOnProject(Project $project, RetentionRelease $release): void
    {
        abort_unless((int) $release->project_id === (int) $project->id, 404);
    }
}
