<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Approval\ApprovalDelegation;
use App\Models\User;
use App\Services\Approval\ApprovalDelegationService;
use App\Services\Core\CompanyDirectory;
use App\Support\Approval\ApprovalDocumentTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalDelegationController extends Controller
{
    public function __construct(
        private readonly ApprovalDelegationService $delegations,
        private readonly CompanyDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ApprovalDelegation::class);

        $seeAll = $request->user()->can('admin.workflows.manage');
        $query = ApprovalDelegation::query()->with(['delegator:id,name', 'delegate:id,name'])->latest('id');
        if (! $seeAll) {
            $query->where('delegator_id', $request->user()->id);
        }

        return Inertia::render('Approvals/Delegations', [
            'delegations' => $query->limit(200)->get()->map(fn (ApprovalDelegation $d) => [
                'id' => $d->id,
                'delegator' => $d->delegator?->name,
                'delegate' => $d->delegate?->name,
                'starts_at' => $d->starts_at?->toIso8601String(),
                'ends_at' => $d->ends_at?->toIso8601String(),
                'document_types' => $d->document_types,
                'reason' => $d->reason,
                'is_active' => $d->is_active,
            ])->all(),
            'users' => $this->directory->membersQuery()->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name, 'description' => $u->email])->all(),
            'documentTypes' => collect(ApprovalDocumentTypes::all())->map(fn ($meta, $type) => [
                'value' => $type,
                'label' => $meta['label'],
            ])->values()->all(),
            'can' => [
                'manage_all' => $seeAll,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ApprovalDelegation::class);

        $data = $request->validate([
            'delegate_id' => ['required', 'integer', 'different:delegator'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'document_types' => ['nullable', 'array', 'max:30'],
            'document_types.*' => ['string', 'max:50'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->delegations->create($request->user(), $data);

        return back()->with('success', 'Delegation saved.');
    }

    public function destroy(Request $request, ApprovalDelegation $approvalDelegation): RedirectResponse
    {
        Gate::authorize('delete', $approvalDelegation);

        $this->delegations->revoke($approvalDelegation, $request->user());

        return back()->with('success', 'Delegation revoked.');
    }
}
