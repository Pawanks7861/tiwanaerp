<?php

use App\Enums\CostHead;
use App\Enums\Finance\ExpenseStatus;
use App\Enums\ProjectRole;
use App\Models\Finance\Expense;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Masters\ExpenseCategory;
use App\Services\Approval\ApprovalService;
use App\Services\Finance\ExpenseService;
use App\Services\Projects\ProjectService;
use App\Support\Permissions\DefaultRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsResourceData;

uses(BuildsResourceData::class);

/**
 * Expenses: the site engineer books and submits; PM (level 1) → Accountant (level 2, holds
 * expenses.approve) approves. Approval posts the amount excluding GST under the category's cost
 * head; marking paid posts nothing.
 */
beforeEach(function () {
    $this->setUpResources();
    [$this->transport, $this->fuel] = $this->inCompany($this->company, fn () => [
        ExpenseCategory::query()->where('name', 'Transport')->value('id'),
        ExpenseCategory::query()->where('name', 'Fuel')->value('id'),
    ]);
});

function expensePayload($test, array $overrides = []): array
{
    return array_replace([
        'expense_category_id' => $test->transport,
        'payee_name' => 'Ganesh Tempo',
        'expense_date' => now()->subDay()->toDateString(),
        'amount' => '1250.50',
        'tax_amount' => '62.53',
        'payment_mode' => 'cash',
        'description' => 'Shuttering material shifting',
        'task_id' => $test->task->id,
    ], $overrides);
}

function bookExpense($test, array $overrides = [], $user = null): Expense
{
    $test->actingInCompany($user ?? $test->engineer, $test->company)
        ->post(route('projects.expenses.store', $test->project), expensePayload($test, $overrides))
        ->assertSessionHasNoErrors();

    return $test->inCompany($test->company, fn () => Expense::query()->latest('id')->firstOrFail());
}

function approveExpense($test, Expense $expense): Expense
{
    return $test->inCompany($test->company, function () use ($test, $expense) {
        $approvals = app(ApprovalService::class);
        $request = $approvals->approve($expense->fresh()->pendingApprovalRequest(), $test->pm);
        $approvals->approve($request, $test->accountant);

        return $expense->fresh();
    });
}

function expenseCosts($test, Expense $expense)
{
    return $test->inCompany($test->company, fn () => ProjectCostEntry::query()
        ->where('source_type', 'expense')->where('source_id', $expense->id)->orderBy('id')->get());
}

test('expenses are numbered per project and computed on the server', function () {
    $first = bookExpense($this);
    $second = bookExpense($this, ['amount' => '99.99', 'tax_amount' => null, 'total_amount' => '1']);

    expect($first->expense_number)->toBe('EXP-PRJ001-0001')
        ->and($second->expense_number)->toBe('EXP-PRJ001-0002')
        ->and($first->status)->toBe(ExpenseStatus::Draft)
        ->and($first->company_id)->toBe($this->company->id)
        ->and($first->cost_head)->toBe(CostHead::Other)
        ->and($first->total_amount)->toBe('1313.03')
        ->and($first->boq_item_id)->toBe($this->boqLine->id) // inherited from the task
        ->and($second->total_amount)->toBe('99.99');

    // Edit (accountant holds expenses.update) and delete a draft.
    $this->actingInCompany($this->accountant, $this->company)
        ->put(route('projects.expenses.update', [$this->project, $second]), expensePayload($this, ['expense_category_id' => $this->fuel, 'amount' => '500']))
        ->assertSessionHasNoErrors();
    expect($second->fresh()->cost_head)->toBe(CostHead::Equipment)->and($second->fresh()->total_amount)->toBe('562.53');

    $this->actingInCompany($this->accountant, $this->company)->delete(route('projects.expenses.destroy', [$this->project, $second]))->assertRedirect();
    expect($this->inCompany($this->company, fn () => Expense::query()->count()))->toBe(1);

    $this->actingInCompany($this->engineer, $this->company)->get(route('projects.expenses.index', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Finance/Expenses/Index')->has('expenses.data', 1));
});

test('validation rejects a non-positive amount and a missing payee', function () {
    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.expenses.store', $this->project), expensePayload($this, ['amount' => '0']))
        ->assertSessionHasErrors('amount');
    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.expenses.store', $this->project), expensePayload($this, ['payee_name' => null]))
        ->assertSessionHasErrors('payee_name');
});

test('approval posts the cost once under the category cost head, and the approved expense is locked', function () {
    $expense = bookExpense($this);
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.expenses.submit', [$this->project, $expense]))->assertSessionHasNoErrors();
    expect($expense->fresh()->status)->toBe(ExpenseStatus::Submitted)
        ->and(expenseCosts($this, $expense))->toHaveCount(0);

    $expense = approveExpense($this, $expense);
    $costs = expenseCosts($this, $expense);
    expect($expense->status)->toBe(ExpenseStatus::Approved)
        ->and($expense->approved_by)->toBe($this->accountant->id)
        ->and($costs)->toHaveCount(1)
        ->and($costs[0]->amount)->toBe('1250.50') // GST excluded
        ->and($costs[0]->cost_head)->toBe(CostHead::Other)
        ->and($costs[0]->task_id)->toBe($this->task->id)
        ->and($costs[0]->boq_line_uid)->toBe($this->boqLine->line_uid);

    // Retrying the final approval is a no-op.
    $this->inCompany($this->company, fn () => app(ExpenseService::class)->approve($expense->fresh(), $this->accountant->id));
    expect(expenseCosts($this, $expense))->toHaveCount(1);

    $this->actingInCompany($this->accountant, $this->company)
        ->put(route('projects.expenses.update', [$this->project, $expense]), expensePayload($this, ['amount' => '1']))
        ->assertForbidden();
    expect(fn () => $this->inCompany($this->company, fn () => app(ExpenseService::class)->update($expense->fresh(), expensePayload($this), $this->accountant)))
        ->toThrow(Exception::class);
    $this->actingInCompany($this->accountant, $this->company)->delete(route('projects.expenses.destroy', [$this->project, $expense]))->assertForbidden();
});

test('marking an expense paid records the cash-out and posts no cost', function () {
    $expense = bookExpense($this);
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.expenses.submit', [$this->project, $expense]));
    $expense = approveExpense($this, $expense);
    $ledgerBefore = $this->costs()->count();

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.expenses.mark-paid', [$this->project, $expense]), ['paid_on' => now()->toDateString()])
        ->assertForbidden(); // needs payments.record

    $this->actingInCompany($this->accountant, $this->company)
        ->post(route('projects.expenses.mark-paid', [$this->project, $expense]), ['paid_on' => now()->toDateString(), 'payment_reference' => 'CASH-17'])
        ->assertSessionHasNoErrors();

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Paid)
        ->and($expense->fresh()->payment_reference)->toBe('CASH-17')
        ->and($this->costs()->count())->toBe($ledgerBefore)
        ->and($this->netCost(CostHead::Other))->toBe('1250.50');

    // Paid twice is refused.
    $this->actingInCompany($this->accountant, $this->company)
        ->post(route('projects.expenses.mark-paid', [$this->project, $expense]), ['paid_on' => now()->toDateString()])
        ->assertForbidden();
});

test('rejection posts nothing; reversal appends a compensating row and re-approval posts once more', function () {
    $rejected = bookExpense($this);
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.expenses.submit', [$this->project, $rejected]));
    $this->inCompany($this->company, fn () => app(ApprovalService::class)->reject($rejected->fresh()->pendingApprovalRequest(), $this->pm, 'No bill attached'));
    expect($rejected->fresh()->status)->toBe(ExpenseStatus::Rejected)->and(expenseCosts($this, $rejected))->toHaveCount(0);

    $expense = bookExpense($this);
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.expenses.submit', [$this->project, $expense]));
    $expense = approveExpense($this, $expense);

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.expenses.reverse', [$this->project, $expense]), ['reason' => 'Wrong category'])
        ->assertForbidden();
    $this->actingInCompany($this->accountant, $this->company)
        ->post(route('projects.expenses.reverse', [$this->project, $expense]), ['reason' => 'Wrong category'])
        ->assertSessionHasNoErrors();

    $expense = $expense->fresh();
    $costs = expenseCosts($this, $expense);
    expect($expense->status)->toBe(ExpenseStatus::Draft)
        ->and($expense->revision)->toBe(1)
        ->and($expense->reversal_reason)->toBe('Wrong category')
        ->and($costs)->toHaveCount(2)
        ->and($costs[1]->is_reversal)->toBeTrue()
        ->and($costs[1]->reverses_id)->toBe($costs[0]->id)
        ->and($costs[1]->amount)->toBe('-1250.50')
        ->and($this->netCost(CostHead::Other))->toBe('0.00');

    // Corrected and re-approved: one new forward posting.
    $this->actingInCompany($this->accountant, $this->company)
        ->put(route('projects.expenses.update', [$this->project, $expense]), expensePayload($this, ['expense_category_id' => $this->fuel, 'amount' => '1100']))
        ->assertSessionHasNoErrors();
    $this->actingInCompany($this->engineer, $this->company)->post(route('projects.expenses.submit', [$this->project, $expense]))->assertSessionHasNoErrors();
    approveExpense($this, $expense);

    expect(expenseCosts($this, $expense))->toHaveCount(3)
        ->and($this->netCost(CostHead::Other))->toBe('0.00')
        ->and($this->netCost(CostHead::Equipment))->toBe('1100.00');

    // A once-approved expense is never deleted.
    $this->actingInCompany($this->accountant, $this->company)
        ->post(route('projects.expenses.reverse', [$this->project, $expense]), ['reason' => 'Undo again'])
        ->assertSessionHasNoErrors();
    $this->actingInCompany($this->accountant, $this->company)->delete(route('projects.expenses.destroy', [$this->project, $expense]))->assertForbidden();
});

test('expenses are isolated by company and project and need permissions', function () {
    $expense = bookExpense($this);

    // Another project's URL does not resolve the expense.
    $this->actingInCompany($this->pm, $this->company)->get(route('projects.expenses.show', [$this->otherProject, $expense]))->assertNotFound();

    // A company member who is not on the project.
    $stranger = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
    $this->actingInCompany($stranger, $this->company)->get(route('projects.expenses.show', [$this->project, $expense]))->assertForbidden();
    $this->actingInCompany($stranger, $this->company)->post(route('projects.expenses.store', $this->project), expensePayload($this))->assertForbidden();

    // A role without expenses.* on the project.
    $quality = $this->createMember($this->company, DefaultRoles::QUALITY_ENGINEER);
    $this->inCompany($this->company, fn () => app(ProjectService::class)->assignMember($this->project, $quality->id, ProjectRole::Engineer));
    $this->actingInCompany($quality, $this->company)->get(route('projects.expenses.index', $this->project))->assertForbidden();

    // Another tenant.
    $other = $this->createCompany();
    $outsider = $this->createMember($other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($outsider, $other)->get(route('projects.expenses.show', [$this->project, $expense]))->assertNotFound();
    $this->actingInCompany($outsider, $other)->post(route('projects.expenses.store', $this->project), expensePayload($this))->assertNotFound();

    // A category from another company is not accepted.
    $foreignCategory = $this->inCompany($other, fn () => ExpenseCategory::query()->value('id'));
    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('projects.expenses.store', $this->project), expensePayload($this, ['expense_category_id' => $foreignCategory]))
        ->assertSessionHasErrors('expense_category_id');
});
