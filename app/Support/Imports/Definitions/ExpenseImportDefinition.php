<?php

namespace App\Support\Imports\Definitions;

use App\Enums\Finance\PaymentMode;
use App\Enums\Imports\ImportMode;
use App\Models\Finance\PettyCashAccount;
use App\Models\Masters\ExpenseCategory;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Finance\ExpenseService;
use App\Support\Imports\Concerns\NormalizesImportValues;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportDefinition;
use App\Support\Imports\ImportField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseImportDefinition extends ImportDefinition
{
    use NormalizesImportValues;

    public function __construct(private readonly ExpenseService $expenses) {}

    public function key(): string
    {
        return 'expenses';
    }

    public function label(): string
    {
        return 'Expenses';
    }

    public function permission(): string
    {
        return 'expenses.import';
    }

    public function requiresProject(): bool
    {
        return true;
    }

    public function modes(): array
    {
        return [ImportMode::Create];
    }

    public function uniqueKey(): ?string
    {
        return null;
    }

    public function authorize(User $user, ?Project $project = null): bool
    {
        if (! $user->can($this->permission()) || ! $user->can('expenses.create')) {
            return false;
        }

        return $project === null || $user->can('view', $project);
    }

    public function instructions(): string
    {
        return 'Creates draft expenses (one row = one expense). Nothing is submitted or approved automatically. Petty cash rows need a valid float name for this project. Example rows labelled EXAMPLE are ignored.';
    }

    public function templateFilename(): string
    {
        return 'Tiwana_ERP_Expenses_Import_Template';
    }

    /**
     * @return list<array{title: string, headings: list<string>, rows: list<list<mixed>>, note?: string}>
     */
    public function referenceData(User $user, ?Project $project = null): array
    {
        $sheets = parent::referenceData($user, $project);
        $sheets[] = [
            'title' => 'Expense categories',
            'headings' => ['Name'],
            'rows' => ExpenseCategory::query()->orderBy('name')->limit(300)->pluck('name')->map(fn ($n) => [$n])->all(),
            'note' => 'Use these names in the Category column.',
        ];
        $sheets[] = [
            'title' => 'Payment modes',
            'headings' => ['Value'],
            'rows' => array_map(fn (PaymentMode $m) => [$m->value], PaymentMode::cases()),
        ];

        return $sheets;
    }

    public function fields(): array
    {
        return [
            new ImportField('expense_date', 'Expense date', true, ['date'], 'date'),
            new ImportField('category', 'Category', true, ['expense_category', 'category_name']),
            new ImportField('description', 'Description', true, ['narration', 'details']),
            new ImportField('amount', 'Amount (excl. tax)', true, ['value'], 'number'),
            new ImportField('tax_amount', 'Tax amount', false, ['gst', 'tax'], 'number'),
            new ImportField('payment_mode', 'Payment mode', true, ['mode'], help: 'cash, bank_transfer, cheque, upi, card, or petty_cash'),
            new ImportField('vendor', 'Vendor', false, ['vendor_code', 'vendor_name']),
            new ImportField('payee_name', 'Payee name', false, ['payee']),
            new ImportField('petty_cash_account', 'Petty cash account', false, ['float', 'petty_cash']),
            new ImportField('reference_no', 'Reference no.', false, ['ref', 'invoice_no']),
            new ImportField('task_wbs', 'Task WBS', false, ['wbs', 'wbs_code']),
            new ImportField('boq_item_code', 'BOQ item code', false, ['boq_code']),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'expense_date' => '2026-04-01',
            'category' => 'Site expenses',
            'description' => 'EXAMPLE ROW — ignored',
            'amount' => '1500',
            'tax_amount' => '0',
            'payment_mode' => 'cash',
            'vendor' => '',
            'payee_name' => 'Example vendor',
            'petty_cash_account' => '',
            'reference_no' => '',
            'task_wbs' => '',
            'boq_item_code' => '',
        ]];
    }

    public function prepareRow(array $mapped, ImportMode $mode, ImportContext $context): array
    {
        if ($this->blankRow($mapped) || $this->isExampleRow($mapped)) {
            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => ['name' => '(skipped)']];
        }

        $project = $context->project ?? $this->fail(['project_id' => ['This import requires a project.']]);
        $data = $this->normalize($project, $mapped);

        $validator = Validator::make($data, [
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'expense_category_id' => ['required', 'integer'],
            'description' => ['required', 'string', 'max:1000'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'tax_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'payment_mode' => ['required', Rule::enum(PaymentMode::class)],
            'vendor_id' => ['nullable', 'integer'],
            'payee_name' => ['nullable', 'string', 'max:150'],
            'petty_cash_account_id' => ['nullable', 'integer'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'task_id' => ['nullable', 'integer'],
            'boq_item_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        if (blank($validated['vendor_id'] ?? null) && blank($validated['payee_name'] ?? null)) {
            $this->fail(['payee_name' => ['Enter a payee name or choose a vendor.']]);
        }

        return [
            'action' => 'create',
            'data' => $validated,
            'existing_id' => null,
            'preview' => [
                'name' => $validated['description'],
                'code' => $validated['expense_date'],
            ],
        ];
    }

    public function commitRow(array $prepared, ImportMode $mode, ImportContext $context): string
    {
        if ($prepared['action'] !== 'create') {
            return 'skipped';
        }

        $project = $context->project ?? $this->fail(['project_id' => ['This import requires a project.']]);
        $this->expenses->create($project, $prepared['data'], $context->actor);

        return 'created';
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    private function normalize(Project $project, array $mapped): array
    {
        $categoryName = $this->str($mapped['category'] ?? null);
        $category = $categoryName
            ? ExpenseCategory::query()->where('is_active', true)->whereRaw('LOWER(name) = ?', [strtolower($categoryName)])->first()
            : null;
        if (! $category) {
            $this->fail(['category' => ['Choose an active expense category name.']]);
        }

        $modeText = strtolower((string) ($this->str($mapped['payment_mode'] ?? null) ?? ''));
        $paymentMode = PaymentMode::tryFrom($modeText)
            ?? PaymentMode::tryFrom(str_replace(' ', '_', $modeText));
        if (! $paymentMode) {
            $this->fail(['payment_mode' => ['Unknown payment mode. Use cash, bank_transfer, cheque, upi, card, or petty_cash.']]);
        }

        $vendorId = null;
        $vendorText = $this->str($mapped['vendor'] ?? null);
        if ($vendorText !== null) {
            $vendor = Vendor::query()->active()
                ->where(function ($q) use ($vendorText) {
                    $q->whereRaw('LOWER(code) = ?', [strtolower($vendorText)])
                        ->orWhereRaw('LOWER(name) = ?', [strtolower($vendorText)]);
                })
                ->first();
            if (! $vendor) {
                $this->fail(['vendor' => ["Unknown vendor \"{$vendorText}\"."]]);
            }
            $vendorId = (int) $vendor->id;
        }

        $pettyId = null;
        if ($paymentMode === PaymentMode::PettyCash) {
            $floatName = $this->str($mapped['petty_cash_account'] ?? null);
            if ($floatName === null) {
                $this->fail(['petty_cash_account' => ['Petty cash expenses need the float name.']]);
            }
            $account = PettyCashAccount::query()->where('project_id', $project->id)->where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [strtolower($floatName)])->first();
            if (! $account) {
                $this->fail(['petty_cash_account' => ["Unknown petty cash account \"{$floatName}\" on this project."]]);
            }
            $pettyId = (int) $account->id;
        }

        $taskId = null;
        $wbs = $this->str($mapped['task_wbs'] ?? null);
        if ($wbs !== null) {
            $taskId = \App\Models\Planning\ProjectTask::query()
                ->where('project_id', $project->id)
                ->whereRaw('LOWER(wbs_code) = ?', [strtolower($wbs)])
                ->value('id');
            if (! $taskId) {
                $this->fail(['task_wbs' => ["Task WBS \"{$wbs}\" was not found."]]);
            }
        }

        $boqItemId = null;
        $boqCode = $this->str($mapped['boq_item_code'] ?? null);
        if ($boqCode !== null) {
            $boqId = \App\Models\Boq\Boq::query()->where('project_id', $project->id)->where('is_current', true)
                ->where('status', \App\Enums\Boq\BoqStatus::Approved)->value('id');
            $boqItemId = $boqId !== null
                ? \App\Models\Boq\BoqItem::query()->where('boq_id', $boqId)->whereRaw('LOWER(item_code) = ?', [strtolower($boqCode)])->value('id')
                : null;
            if (! $boqItemId) {
                $this->fail(['boq_item_code' => ["BOQ line \"{$boqCode}\" was not found."]]);
            }
        }

        return [
            'expense_date' => $this->date($mapped['expense_date'] ?? null) ?? $this->fail(['expense_date' => ['Enter a valid expense date.']]),
            'expense_category_id' => (int) $category->id,
            'description' => $this->str($mapped['description'] ?? null),
            'amount' => $this->number($mapped['amount'] ?? null),
            'tax_amount' => $this->number($mapped['tax_amount'] ?? null) ?? '0',
            'payment_mode' => $paymentMode->value,
            'vendor_id' => $vendorId,
            'payee_name' => $this->str($mapped['payee_name'] ?? null),
            'petty_cash_account_id' => $pettyId,
            'reference_no' => $this->str($mapped['reference_no'] ?? null),
            'task_id' => $taskId,
            'boq_item_id' => $boqItemId,
        ];
    }
}
