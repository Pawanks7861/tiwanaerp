<?php

namespace App\Policies;

use App\Models\Crm\Client;
use App\Models\Equipment\Equipment;
use App\Models\Labour\Labour;
use App\Models\Masters\EquipmentType;
use App\Models\Masters\ExpenseCategory;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Material;
use App\Models\Masters\MaterialCategory;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\TaxRate;
use App\Models\Masters\Unit;
use App\Models\Masters\Vendor;
use App\Models\Masters\Warehouse;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One policy class for all company master data. Each master model gets its own instance bound
 * with its permission prefix (see AppServiceProvider), because the Gate does not pass the model
 * class to viewAny/create.
 */
class MasterPolicy
{
    /** @var array<class-string<Model>, string> */
    public const PERMISSIONS = [
        Material::class => 'masters.items',
        Unit::class => 'masters.units',
        MaterialCategory::class => 'masters.categories',
        TaxRate::class => 'masters.tax_rates',
        Vendor::class => 'masters.vendors',
        Subcontractor::class => 'masters.subcontractors',
        LabourTrade::class => 'masters.labour_trades',
        EquipmentType::class => 'masters.equipment_types',
        Warehouse::class => 'masters.warehouses',
        ExpenseCategory::class => 'masters.expense_categories',
        Client::class => 'crm.clients',
        Labour::class => 'labour',
        Equipment::class => 'equipment',
    ];

    public function __construct(private readonly string $prefix) {}

    public static function containerKey(string $prefix): string
    {
        return "policy.{$prefix}";
    }

    public function viewAny(User $user): bool
    {
        return $user->can("{$this->prefix}.view");
    }

    public function view(User $user, Model $record): bool
    {
        return $this->ownsRecord($record) && $user->can("{$this->prefix}.view");
    }

    public function create(User $user): bool
    {
        return $user->can("{$this->prefix}.create");
    }

    public function update(User $user, Model $record): bool
    {
        return $this->ownsRecord($record) && $user->can("{$this->prefix}.update");
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->ownsRecord($record) && $user->can("{$this->prefix}.delete");
    }

    private function ownsRecord(Model $record): bool
    {
        return (int) $record->getAttribute('company_id') === app(CurrentCompany::class)->id();
    }
}
