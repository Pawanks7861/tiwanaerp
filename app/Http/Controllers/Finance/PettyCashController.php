<?php

namespace App\Http\Controllers\Finance;

use App\Enums\Finance\PettyCashTxnType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Integrations\Tally\TallyStatusPresenter;
use App\Models\Finance\PettyCashAccount;
use App\Models\Finance\PettyCashTransaction;
use App\Models\Projects\Project;
use App\Models\User;
use App\Rules\CompanyMember;
use App\Services\Finance\PettyCashService;
use App\Support\Math\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PettyCashController extends Controller
{
    public function __construct(private readonly PettyCashService $pettyCash) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [PettyCashAccount::class, $project]);

        $accounts = $project->pettyCashAccounts()->with('holder:id,name')->orderBy('name')->get();

        return Inertia::render('Finance/PettyCash/Index', [
            'project' => ProjectHeader::for($project),
            'accounts' => $accounts->map(fn (PettyCashAccount $a) => $this->row($a))->all(),
            'holders' => $this->holderOptions($project),
            'can' => ['create' => $request->user()->can('create', [PettyCashAccount::class, $project])],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [PettyCashAccount::class, $project]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'holder_user_id' => ['required', new CompanyMember],
            'limit_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ], [], ['holder_user_id' => 'holder']);
        if ($project->pettyCashAccounts()->where('name', trim($data['name']))->exists()) {
            return back()->withErrors(['name' => 'This project already has a float with that name.']);
        }
        $account = $this->pettyCash->createAccount($project, $data);

        return redirect()->route('projects.petty-cash.show', [$project, $account])->with('success', "Petty cash float \"{$account->name}\" opened.");
    }

    public function show(Request $request, Project $project, PettyCashAccount $pettyCashAccount): Response
    {
        Gate::authorize('view', $pettyCashAccount);
        $user = $request->user();
        $pettyCashAccount->load('holder:id,name');

        $transactions = PettyCashTransaction::query()->where('petty_cash_account_id', $pettyCashAccount->id)
            ->with(['expense:id,expense_number,project_id', 'creator:id,name'])
            ->orderBy('txn_date')->orderBy('id')->get();
        $running = Decimal::zero();
        $rows = $transactions->map(function (PettyCashTransaction $t) use (&$running) {
            $signed = $t->type->sign() > 0 ? Decimal::of($t->amount) : Decimal::of($t->amount)->negate();
            $running = $running->plus($signed);

            return [
                'id' => $t->id,
                'date' => $t->txn_date->toDateString(),
                'type' => $t->type->value,
                'type_label' => $t->reverses_id ? $t->type->label().' (reversal)' : $t->type->label(),
                'in' => $signed->isPositive() ? $signed->toMoney() : null,
                'out' => $signed->isNegative() ? $signed->negate()->toMoney() : null,
                'balance' => $running->toMoney(),
                'expense' => $t->expense ? ['id' => $t->expense->id, 'number' => $t->expense->expense_number] : null,
                'remarks' => $t->remarks,
                'by' => $t->creator?->name,
                'tally' => $t->type === PettyCashTxnType::ExpenseOut ? null : app(TallyStatusPresenter::class)->for($t),
            ];
        })->reverse()->values();

        return Inertia::render('Finance/PettyCash/Show', [
            'project' => ProjectHeader::for($project),
            'account' => $this->row($pettyCashAccount),
            'transactions' => $rows->all(),
            'can' => [
                'update' => $user->can('update', $pettyCashAccount),
                'fund' => $pettyCashAccount->is_active && $user->can('fund', $pettyCashAccount),
            ],
            'today' => now()->toDateString(),
        ]);
    }

    public function update(Request $request, Project $project, PettyCashAccount $pettyCashAccount): RedirectResponse
    {
        Gate::authorize('update', $pettyCashAccount);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'limit_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'is_active' => ['required', 'boolean'],
        ]);
        $this->pettyCash->updateAccount($pettyCashAccount, $data);

        return back()->with('success', 'Petty cash float updated.');
    }

    public function fund(Request $request, Project $project, PettyCashAccount $pettyCashAccount): RedirectResponse
    {
        Gate::authorize('fund', $pettyCashAccount);

        $this->pettyCash->fund($pettyCashAccount, $request->user(), $this->movement($request));

        return back()->with('success', 'Float funded. Funding is a cash transfer, not a project cost.');
    }

    public function returnCash(Request $request, Project $project, PettyCashAccount $pettyCashAccount): RedirectResponse
    {
        Gate::authorize('update', $pettyCashAccount);

        $this->pettyCash->returnCash($pettyCashAccount, $request->user(), $this->movement($request));

        return back()->with('success', 'Cash returned from the float.');
    }

    /**
     * @return array<string, mixed>
     */
    private function movement(Request $request): array
    {
        return $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'txn_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PettyCashAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'holder' => $account->holder?->name,
            'limit_amount' => $account->limit_amount,
            'is_active' => $account->is_active,
            'balance' => $account->balance()->toMoney(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function holderOptions(Project $project): array
    {
        return User::query()->where('is_active', true)
            ->whereHas('memberships', fn ($m) => $m->where('company_id', $project->company_id)->where('is_active', true))
            ->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name, 'description' => $u->email])->all();
    }
}
