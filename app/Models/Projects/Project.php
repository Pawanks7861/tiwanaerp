<?php

namespace App\Models\Projects;

use App\Enums\ProjectStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\ProjectBudget;
use App\Models\Boq\ProjectBudgetLine;
use App\Models\Boq\RateAnalysis;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Crm\Client;
use App\Models\Documents\Document;
use App\Models\Documents\DocumentFolder;
use App\Models\Documents\Drawing;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Equipment\EquipmentFuelLog;
use App\Models\Equipment\EquipmentRepair;
use App\Models\Equipment\EquipmentUsageLog;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\Expense;
use App\Models\Finance\Payment;
use App\Models\Finance\PettyCashAccount;
use App\Models\Finance\RetentionRelease;
use App\Models\Finance\VendorBill;
use App\Models\Inventory\MaterialIssue;
use App\Models\Inventory\MaterialReturn;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockTransfer;
use App\Models\Labour\LabourAdvance;
use App\Models\Labour\LabourAttendance;
use App\Models\Labour\LabourPayment;
use App\Models\Masters\Warehouse;
use App\Models\Planning\ProjectMilestone;
use App\Models\Planning\ProjectTask;
use App\Models\Procurement\Grn;
use App\Models\Procurement\MaterialRequest;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\Rfq;
use App\Models\Quality\Ncr;
use App\Models\Quality\QualityInspection;
use App\Models\SiteExecution\Dpr;
use App\Models\SiteExecution\SiteDiary;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\Subcontract\WorkOrder;
use App\Models\User;
use Database\Factories\Projects\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'client_id', 'code', 'name', 'description', 'project_type', 'address', 'city', 'state_code',
    'latitude', 'longitude', 'project_manager_id', 'start_date', 'expected_end_date',
    'actual_end_date', 'contract_value',
])]
#[UseFactory(ProjectFactory::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use Auditable, BelongsToCompany, Blameable, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'start_date' => 'date',
            'expected_end_date' => 'date',
            'actual_end_date' => 'date',
            'contract_value' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * Projects the user may see: everything with projects.view_all, otherwise active memberships only.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('projects.view_all')) {
            return;
        }

        $query->whereHas('members', fn (Builder $m) => $m->where('user_id', $user->id)->where('is_active', true));
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * @return HasMany<ProjectUser, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectUser::class);
    }

    /**
     * @return BelongsToMany<User, $this, ProjectUser>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_users')
            ->using(ProjectUser::class)
            ->withPivot(['id', 'project_role', 'is_active'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Warehouse, $this>
     */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /**
     * @return HasMany<ProjectSetting, $this>
     */
    public function settings(): HasMany
    {
        return $this->hasMany(ProjectSetting::class);
    }

    /**
     * @return HasMany<Boq, $this>
     */
    public function boqs(): HasMany
    {
        return $this->hasMany(Boq::class);
    }

    /**
     * Project-specific rate analyses (company library entries have project_id null).
     *
     * @return HasMany<RateAnalysis, $this>
     */
    public function rateAnalyses(): HasMany
    {
        return $this->hasMany(RateAnalysis::class);
    }

    /**
     * @return HasMany<ProjectBudget, $this>
     */
    public function budgets(): HasMany
    {
        return $this->hasMany(ProjectBudget::class);
    }

    /**
     * @return HasManyThrough<ProjectBudgetLine, ProjectBudget, $this>
     */
    public function budgetLines(): HasManyThrough
    {
        return $this->hasManyThrough(ProjectBudgetLine::class, ProjectBudget::class);
    }

    /**
     * @return HasMany<ProjectTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    /**
     * @return HasMany<ProjectMilestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class);
    }

    /**
     * @return HasMany<MaterialRequest, $this>
     */
    public function materialRequests(): HasMany
    {
        return $this->hasMany(MaterialRequest::class);
    }

    /**
     * @return HasMany<Rfq, $this>
     */
    public function rfqs(): HasMany
    {
        return $this->hasMany(Rfq::class);
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * @return HasMany<Grn, $this>
     */
    public function grns(): HasMany
    {
        return $this->hasMany(Grn::class);
    }

    /**
     * @return HasMany<MaterialIssue, $this>
     */
    public function materialIssues(): HasMany
    {
        return $this->hasMany(MaterialIssue::class);
    }

    /**
     * @return HasMany<StockTransfer, $this>
     */
    public function stockTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class);
    }

    /**
     * @return HasMany<MaterialReturn, $this>
     */
    public function materialReturns(): HasMany
    {
        return $this->hasMany(MaterialReturn::class);
    }

    /**
     * @return HasMany<StockAdjustment, $this>
     */
    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class);
    }

    /**
     * @return HasMany<SiteDiary, $this>
     */
    public function siteDiaries(): HasMany
    {
        return $this->hasMany(SiteDiary::class);
    }

    /**
     * @return HasMany<Dpr, $this>
     */
    public function dprs(): HasMany
    {
        return $this->hasMany(Dpr::class);
    }

    /**
     * @return HasMany<LabourAttendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(LabourAttendance::class);
    }

    /**
     * @return HasMany<LabourAdvance, $this>
     */
    public function advances(): HasMany
    {
        return $this->hasMany(LabourAdvance::class);
    }

    /**
     * @return HasMany<LabourPayment, $this>
     */
    public function labourPayments(): HasMany
    {
        return $this->hasMany(LabourPayment::class);
    }

    /**
     * @return HasMany<WorkOrder, $this>
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * @return HasMany<SubcontractorBill, $this>
     */
    public function subcontractorBills(): HasMany
    {
        return $this->hasMany(SubcontractorBill::class);
    }

    /**
     * @return HasMany<EquipmentAssignment, $this>
     */
    public function equipmentAssignments(): HasMany
    {
        return $this->hasMany(EquipmentAssignment::class);
    }

    /**
     * @return HasMany<EquipmentUsageLog, $this>
     */
    public function equipmentUsageLogs(): HasMany
    {
        return $this->hasMany(EquipmentUsageLog::class);
    }

    /**
     * @return HasMany<EquipmentFuelLog, $this>
     */
    public function equipmentFuelLogs(): HasMany
    {
        return $this->hasMany(EquipmentFuelLog::class);
    }

    /**
     * @return HasMany<EquipmentRepair, $this>
     */
    public function equipmentRepairs(): HasMany
    {
        return $this->hasMany(EquipmentRepair::class);
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * @return HasMany<PettyCashAccount, $this>
     */
    public function pettyCashAccounts(): HasMany
    {
        return $this->hasMany(PettyCashAccount::class);
    }

    /**
     * @return HasMany<ClientInvoice, $this>
     */
    public function clientInvoices(): HasMany
    {
        return $this->hasMany(ClientInvoice::class);
    }

    /**
     * @return HasMany<VendorBill, $this>
     */
    public function vendorBills(): HasMany
    {
        return $this->hasMany(VendorBill::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<RetentionRelease, $this>
     */
    public function retentionReleases(): HasMany
    {
        return $this->hasMany(RetentionRelease::class);
    }

    /**
     * @return HasMany<QualityInspection, $this>
     */
    public function qualityInspections(): HasMany
    {
        return $this->hasMany(QualityInspection::class);
    }

    /**
     * @return HasMany<Ncr, $this>
     */
    public function ncrs(): HasMany
    {
        return $this->hasMany(Ncr::class);
    }

    /**
     * @return HasMany<Drawing, $this>
     */
    public function drawings(): HasMany
    {
        return $this->hasMany(Drawing::class);
    }

    /**
     * @return HasMany<DocumentFolder, $this>
     */
    public function documentFolders(): HasMany
    {
        return $this->hasMany(DocumentFolder::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
