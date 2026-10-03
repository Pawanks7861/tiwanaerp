<?php

namespace App\Support\Masters\Definitions;

use App\Enums\CostHead;
use App\Models\Masters\ExpenseCategory;
use App\Support\Masters\MasterDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseCategoryDefinition extends MasterDefinition
{
    public function slug(): string
    {
        return 'expense-categories';
    }

    public function model(): string
    {
        return ExpenseCategory::class;
    }

    public function title(): string
    {
        return 'Expense Categories';
    }

    public function singular(): string
    {
        return 'Expense Category';
    }

    public function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 100],
            ['name' => 'cost_head', 'label' => 'Cost head', 'type' => 'select', 'options' => 'cost_heads', 'required' => true, 'help' => 'Where this expense is reported in project cost.'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:100', $this->unique('expense_categories', 'name', $record)],
            'cost_head' => ['required', Rule::enum(CostHead::class)],
            'is_active' => ['boolean'],
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'cost_head_label', 'label' => 'Cost head'],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }

    public function row(Model $record): array
    {
        /** @var ExpenseCategory $record */
        return parent::row($record) + ['cost_head_label' => $record->cost_head?->label()];
    }

    public function options(Request $request): array
    {
        return ['cost_heads' => CostHead::options()];
    }
}
