<?php

use App\Enums\CostHead;
use App\Enums\Finance\ExpenseStatus;
use App\Enums\Finance\PettyCashTxnType;
use App\Models\Finance\Expense;
use App\Models\Finance\PettyCashAccount;
use App\Models\Finance\PettyCashTransaction;
use App\Models\Masters\ExpenseCategory;
use App\Services\Approval\ApprovalService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\PettyCashService;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

/**
 * A site cashier (Accountant role, on the project via projects.view_all) holds a float with a
 * 10,000 limit. The project accountant funds it; the cashier books petty cash expenses, which the
 * PM and the accountant approve.
 */
beforeEach(function () {
    $this->setUpResources();
    $this->cashier = $this->createMember($this->company, DefaultRoles::ACCOUNTANT);
    $this->food = $this->inCompany($this->company, fn () => ExpenseCategory::query()->where('name', 'Food')->value('id'));

    $this->actingInCompany($this->accountant, $this->company)->post(route('projects.petty-cash.store', $this->project), [
        'name' => 'Site float', 'holder_user_id' => $this->cashier->id, 'limit_amount' => '10000',
    ])->assertSessionHasNoErrors();
    $this->account = $this->inCompany($this->company, fn () => PettyCashAccount::query()->sole());
});

function fundFloat($test, string $amount, $user = null)
{
    return $test->actingInCompany($user ?? $test->accountant, $test->company)
        ->post(route('projects.petty-cash.fund', [$test->project, $test->account]), ['amount' => $amount, 'txn_date' => now()->toDateString(), 'remarks' => 'Cash handed over']);
}

function floatBalance($test): string
{
    return $test->inCompany($test->company, fn () => PettyCashAccount::query()->findOrFail($test->account->id)->balance()->toMoney());
}

function pettyExpense($test, string $amount, string $tax = '0'): Expense
{
    $test->actingInCompany($test->cashier, $test->company)->post(route('projects.expenses.store', $test->project), [
        'expense_category_id' => $test->food,
        'payee_name' => 'Annapurna Mess',
        'expense_date' => now()->toDateString(),
        'amount' => $amount,
        'tax_amount' => $tax,
        'payment_mode' => 'petty_cash',
        'petty_cash_account_id' => $test->account->id,
        'description' => 'Labour tea and snacks',
    ])->assertSessionHasNoErrors();

    return $test->inCompany($test->company, fn () => Expense::query()->latest('id')->firstOrFail());
}

function approvePetty($test, Expense $expense): Expense
{
    return $test->inCompany($test->company, function () use ($test, $expense) {
        $approvals = app(ApprovalService::class);
        $request = $approvals->approve($expense->fresh()->pendingApprovalRequest(), $test->pm);
        $approvals->approve($request, $test->accountant);

        return $expense->fresh();
    });
}

test('a float is opened for a project member and funding is a cash transfer, not cost', function () {
    expect($this->account->project_id)->toBe($this->project->id)
        ->and($this->account->holder_user_id)->toBe($this->cashier->id)
        ->and($this->account->limit_amount)->toBe('10000.00')
        ->and(floatBalance($this))->toBe('0.00');

    fundFloat($this, '6000')->assertSessionHasNoErrors();
    fundFloat($this, '2500.75')->assertSessionHasNoErrors();
    expect(floatBalance($this))->toBe('8500.75')
        ->and($this->costs())->toHaveCount(0);

    // Above the limit.
    fundFloat($this, '1499.26')->assertSessionHasErrors('amount');
    expect(floatBalance($this))->toBe('8500.75');

    // The holder must be on the project.
    $stranger = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
    $this->actingInCompany($this->accountant, $this->company)->post(route('projects.petty-cash.store', $this->project), [
        'name' => 'Stranger float', 'holder_user_id' => $stranger->id,
    ])->assertSessionHasErrors('holder_user_id');

    $this->actingInCompany($this->cashier, $this->company)->get(route('projects.petty-cash.show', [$this->project, $this->account]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Finance/PettyCash/Show')
            ->where('account.balance', '8500.75')
            ->has('transactions', 2));
});

test('an approved petty cash expense drains the float once and posts cost once', function () {
    fundFloat($this, '5000')->assertSessionHasNoErrors();
    $expense = pettyExpense($this, '1200', '60');
    $this->actingInCompany($this->cashier, $this->company)->post(route('projects.expenses.submit', [$this->project, $expense]))->assertSessionHasNoErrors();
    expect(floatBalance($this))->toBe('5000.00'); // nothing leaves before approval

    $expense = approvePetty($this, $expense);
    expect($expense->status)->toBe(ExpenseStatus::Paid)
        ->and($expense->payment_reference)->toBe('Petty cash')
        ->and(floatBalance($this))->toBe('3740.00')
        ->and($this->netCost(CostHead::Other))->toBe('1200.00');

    // Retried approval and a direct second drain are both no-ops.
    $this->inCompany($this->company, fn () => app(ExpenseService::class)->approve($expense->fresh(), $this->accountant->id));
    $this->inCompany($this->company, fn () => app(PettyCashService::class)->drainForExpense($expense->fresh(), $this->accountant->id));
    expect(floatBalance($this))->toBe('3740.00')
        ->and($this->costs())->toHaveCount(1)
        ->and($this->inCompany($this->company, fn () => PettyCashTransaction::query()->where('type', PettyCashTxnType::ExpenseOut)->count()))->toBe(1);

    // A petty cash expense is already paid: mark-paid is refused.
    $this->actingInCompany($this->accountant, $this->company)
        ->post(route('projects.expenses.mark-paid', [$this->project, $expense]), ['paid_on' => now()->toDateString()])
        ->assertForbidden();

    // Reversal refunds the float and the cost through compensating rows.
    $this->actingInCompany($this->accountant, $this->company)
        ->post(route('projects.expenses.reverse', [$this->project, $expense]), ['reason' => 'Duplicate voucher'])
        ->assertSessionHasNoErrors();
    expect(floatBalance($this))->toBe('5000.00')
        ->and($this->netCost(CostHead::Other))->toBe('0.00')
        ->and($this->inCompany($this->company, fn () => PettyCashTransaction::query()->count()))->toBe(3);
});

test('an expense larger than the float cannot be submitted or approved', function () {
    fundFloat($this, '1000')->assertSessionHasNoErrors();
    $expense = pettyExpense($this, '1000', '0.01');
    $this->actingInCompany($this->cashier, $this->company)->post(route('projects.expenses.submit', [$this->project, $expense]))->assertSessionHasErrors('expense');
    expect($expense->fresh()->status)->toBe(ExpenseStatus::Draft);

    // Submitted while funded, then the float is emptied before approval: approval is refused.
    fundFloat($this, '500')->assertSessionHasNoErrors();
    $this->actingInCompany($this->cashier, $this->company)->post(route('projects.expenses.submit', [$this->project, $expense]))->assertSessionHasNoErrors();
    $this->actingInCompany($this->accountant, $this->company)
        ->post(route('projects.petty-cash.return', [$this->project, $this->account]), ['amount' => '1000', 'txn_date' => now()->toDateString()])
        ->assertSessionHasNoErrors();

    expect(fn () => approvePetty($this, $expense))->toThrow(Exception::class);
    expect(floatBalance($this))->toBe('500.00')
        ->and($this->costs())->toHaveCount(0);
});

test('returns are bounded by the balance and the ledger stays append-only', function () {
    fundFloat($this, '3000')->assertSessionHasNoErrors();
    $return = fn (string $amount) => $this->actingInCompany($this->accountant, $this->company)
        ->post(route('projects.petty-cash.return', [$this->project, $this->account]), ['amount' => $amount, 'txn_date' => now()->toDateString()]);

    $return('3000.01')->assertSessionHasErrors('amount');
    $return('750.25')->assertSessionHasNoErrors();
    expect(floatBalance($this))->toBe('2249.75');

    $rows = $this->inCompany($this->company, fn () => PettyCashTransaction::query()->orderBy('id')->get());
    expect($rows->pluck('type')->all())->toBe([PettyCashTxnType::FundIn, PettyCashTxnType::ReturnOut])
        ->and($rows->pluck('amount')->all())->toBe(['3000.00', '750.25']);

    // The limit cannot drop below the balance.
    $this->actingInCompany($this->accountant, $this->company)
        ->put(route('projects.petty-cash.update', [$this->project, $this->account]), ['name' => 'Site float', 'limit_amount' => '2000', 'is_active' => true])
        ->assertSessionHasErrors('limit_amount');
});

test('only the holder or a petty cash manager can spend; funding needs petty_cash.fund; tenants are isolated', function () {
    fundFloat($this, '2000')->assertSessionHasNoErrors();

    // Site engineer: no petty_cash.fund, cannot open or fund floats, cannot book against one.
    fundFloat($this, '100', $this->engineer)->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.petty-cash.store', $this->project), [
        'name' => 'Mine', 'holder_user_id' => $this->engineer->id,
    ])->assertForbidden();
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.expenses.store', $this->project), [
        'expense_category_id' => $this->food, 'payee_name' => 'Mess', 'expense_date' => now()->toDateString(), 'amount' => '10',
        'payment_mode' => 'petty_cash', 'petty_cash_account_id' => $this->account->id, 'description' => 'Tea',
    ])->assertSessionHasErrors('petty_cash_account_id');

    // A float of another project is not usable here.
    $otherFloat = $this->inCompany($this->company, fn () => app(PettyCashService::class)->createAccount($this->otherProject, ['name' => 'B float', 'holder_user_id' => $this->pm->id]));
    $this->actingInCompany($this->cashier, $this->company)->post(route('projects.expenses.store', $this->project), [
        'expense_category_id' => $this->food, 'payee_name' => 'Mess', 'expense_date' => now()->toDateString(), 'amount' => '10',
        'payment_mode' => 'petty_cash', 'petty_cash_account_id' => $otherFloat->id, 'description' => 'Tea',
    ])->assertSessionHasErrors('petty_cash_account_id');
    $this->actingInCompany($this->accountant, $this->company)->get(route('projects.petty-cash.show', [$this->project, $otherFloat]))->assertNotFound();

    // Another tenant.
    $other = $this->createCompany();
    $outsider = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($outsider, $other)->get(route('projects.petty-cash.show', [$this->project, $this->account]))->assertNotFound();
    $this->actingInCompany($outsider, $other)
        ->post(route('projects.petty-cash.fund', [$this->project, $this->account]), ['amount' => '1', 'txn_date' => now()->toDateString()])
        ->assertNotFound();
    expect(floatBalance($this))->toBe('2000.00');
});
