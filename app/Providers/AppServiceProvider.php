<?php

namespace App\Providers;

use App\Contracts\Files\FileSecurityScannerInterface;
use App\Events\Approval\ApprovalCompleted;
use App\Events\Approval\ApprovalRejected;
use App\Events\Approval\ApprovalRequested;
use App\Events\Chat\MessageSent;
use App\Events\Finance\ClientInvoiceCertified;
use App\Events\Finance\PaymentReceived;
use App\Events\Planning\TaskAssigned;
use App\Events\Procurement\GrnApproved;
use App\Events\Procurement\MaterialRequestSubmitted;
use App\Events\Procurement\PurchaseOrderApproved;
use App\Events\Quality\NcrRaised;
use App\Listeners\PostGrnStock;
use App\Listeners\SendApprovalNotifications;
use App\Listeners\SendChatNotification;
use App\Listeners\SendOperationalNotifications;
use App\Listeners\SendProcurementNotifications;
use App\Models\Approval\ApprovalRequest;
use App\Models\Approval\ApprovalWorkflow;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqSection;
use App\Models\Boq\ProjectBudget;
use App\Models\Boq\ProjectBudgetLine;
use App\Models\Boq\RateAnalysis;
use App\Models\Boq\RateAnalysisItem;
use App\Models\Core\Attachment;
use App\Models\Core\Company;
use App\Models\Core\CompanySetting;
use App\Models\Core\CompanyUser;
use App\Models\Core\DocumentNumberFormat;
use App\Models\Core\FinancialYear;
use App\Models\Core\Role;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Models\Crm\Quotation;
use App\Models\Crm\QuotationItem;
use App\Models\Documents\Document;
use App\Models\Documents\DocumentFolder;
use App\Models\Documents\DocumentVersion;
use App\Models\Documents\Drawing;
use App\Models\Documents\DrawingRevision;
use App\Models\Equipment\Equipment;
use App\Models\Equipment\EquipmentAssignment;
use App\Models\Equipment\EquipmentFuelLog;
use App\Models\Equipment\EquipmentRepair;
use App\Models\Equipment\EquipmentUsageLog;
use App\Models\Files\ExternalPreviewToken;
use App\Models\Files\FileExternalAccess;
use App\Models\Finance\ClientInvoice;
use App\Models\Finance\ClientInvoiceItem;
use App\Models\Finance\Expense;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentAllocation;
use App\Models\Finance\PettyCashAccount;
use App\Models\Finance\PettyCashTransaction;
use App\Models\Finance\ProjectCostEntry;
use App\Models\Finance\RetentionRelease;
use App\Models\Finance\VendorBill;
use App\Models\Finance\VendorBillItem;
use App\Models\Integrations\TallyConnection;
use App\Models\Integrations\TallyCostCentreMapping;
use App\Models\Integrations\TallyLedgerMapping;
use App\Models\Integrations\TallyMasterMapping;
use App\Models\Integrations\TallySyncRecord;
use App\Models\Inventory\LowStockAlert;
use App\Models\Inventory\MaterialIssue;
use App\Models\Inventory\MaterialIssueItem;
use App\Models\Inventory\MaterialReturn;
use App\Models\Inventory\MaterialReturnItem;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAdjustmentItem;
use App\Models\Inventory\StockTransaction;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\StockTransferItem;
use App\Models\Inventory\StockTransferReceipt;
use App\Models\Inventory\StockTransferReceiptItem;
use App\Models\Labour\Labour;
use App\Models\Labour\LabourAdvance;
use App\Models\Labour\LabourAttendance;
use App\Models\Labour\LabourPayment;
use App\Models\Labour\LabourPaymentLine;
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
use App\Models\Planning\ProgressEntry;
use App\Models\Planning\ProjectMilestone;
use App\Models\Planning\ProjectTask;
use App\Models\Planning\TaskDependency;
use App\Models\Planning\TaskOverdueAlert;
use App\Models\Procurement\BidComparison;
use App\Models\Procurement\Grn;
use App\Models\Procurement\GrnItem;
use App\Models\Procurement\MaterialRequest;
use App\Models\Procurement\MaterialRequestItem;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderItem;
use App\Models\Procurement\PurchaseOrderRevision;
use App\Models\Procurement\Rfq;
use App\Models\Procurement\RfqItem;
use App\Models\Procurement\RfqVendor;
use App\Models\Procurement\VendorQuotation;
use App\Models\Procurement\VendorQuotationItem;
use App\Models\Projects\Project;
use App\Models\Projects\ProjectUser;
use App\Models\Projects\Site;
use App\Models\Quality\Ncr;
use App\Models\Quality\QualityChecklist;
use App\Models\Quality\QualityChecklistItem;
use App\Models\Quality\QualityInspection;
use App\Models\Quality\QualityInspectionItem;
use App\Models\Reports\ReportExport;
use App\Models\SiteExecution\Dpr;
use App\Models\SiteExecution\DprEquipment;
use App\Models\SiteExecution\DprItem;
use App\Models\SiteExecution\DprLabour;
use App\Models\SiteExecution\DprMaterial;
use App\Models\SiteExecution\SiteDiary;
use App\Models\SiteExecution\SiteDiaryEquipment;
use App\Models\SiteExecution\SiteDiaryLabour;
use App\Models\SiteExecution\SiteDiaryMaterial;
use App\Models\SiteExecution\SiteDiaryPhoto;
use App\Models\SiteExecution\SiteDiaryWorkItem;
use App\Models\Subcontract\SubcontractorBill;
use App\Models\Subcontract\SubcontractorBillItem;
use App\Models\Subcontract\WorkOrder;
use App\Models\Subcontract\WorkOrderItem;
use App\Models\Subcontract\WorkOrderMilestone;
use App\Models\User;
use App\Policies\AttachmentPolicy;
use App\Policies\BoqPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\Crm\LeadPolicy;
use App\Policies\Crm\QuotationPolicy;
use App\Policies\Documents\DocumentPolicy;
use App\Policies\Documents\DrawingPolicy;
use App\Policies\Equipment\EquipmentAssignmentPolicy;
use App\Policies\Equipment\EquipmentFuelLogPolicy;
use App\Policies\Equipment\EquipmentRepairPolicy;
use App\Policies\Equipment\EquipmentUsageLogPolicy;
use App\Policies\Finance\ClientInvoicePolicy;
use App\Policies\Finance\ExpensePolicy;
use App\Policies\Finance\PaymentPolicy;
use App\Policies\Finance\PettyCashAccountPolicy;
use App\Policies\Finance\RetentionReleasePolicy;
use App\Policies\Finance\VendorBillPolicy;
use App\Policies\Inventory\MaterialIssuePolicy;
use App\Policies\Inventory\MaterialReturnPolicy;
use App\Policies\Inventory\StockAdjustmentPolicy;
use App\Policies\Inventory\StockTransferPolicy;
use App\Policies\Labour\LabourAdvancePolicy;
use App\Policies\Labour\LabourAttendancePolicy;
use App\Policies\Labour\LabourPaymentPolicy;
use App\Policies\MasterPolicy;
use App\Policies\Procurement\BidComparisonPolicy;
use App\Policies\Procurement\GrnPolicy;
use App\Policies\Procurement\MaterialRequestPolicy;
use App\Policies\Procurement\PurchaseOrderPolicy;
use App\Policies\Procurement\RfqPolicy;
use App\Policies\Procurement\VendorQuotationPolicy;
use App\Policies\ProjectBudgetPolicy;
use App\Policies\ProjectMilestonePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectTaskPolicy;
use App\Policies\Quality\NcrPolicy;
use App\Policies\Quality\QualityChecklistPolicy;
use App\Policies\Quality\QualityInspectionPolicy;
use App\Policies\RateAnalysisPolicy;
use App\Policies\RolePolicy;
use App\Policies\SiteExecution\DprPolicy;
use App\Policies\SiteExecution\SiteDiaryPolicy;
use App\Policies\SitePolicy;
use App\Policies\Subcontract\SubcontractorBillPolicy;
use App\Policies\Subcontract\WorkOrderPolicy;
use App\Policies\UserPolicy;
use App\Services\Files\Cad\CadPreviewManager;
use App\Services\Files\LocalPreviewConverter;
use App\Services\Files\PreviewConverter;
use App\Services\Uploads\NullFileSecurityScanner;
use App\Support\Reports\DashboardCache;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentCompany::class);
        $this->app->singleton(FileSecurityScannerInterface::class, NullFileSecurityScanner::class);
        $this->app->singleton(PreviewConverter::class, fn ($app) => new LocalPreviewConverter($app->make(CadPreviewManager::class)));
        $this->app->scoped(DashboardCache::class);

        foreach (MasterPolicy::PERMISSIONS as $prefix) {
            $this->app->singleton(MasterPolicy::containerKey($prefix), fn () => new MasterPolicy($prefix));
        }
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        Model::shouldBeStrict(! $this->app->isProduction());

        // Stable names in polymorphic columns (attachments, audit logs, approvals, roles).
        Relation::enforceMorphMap([
            'user' => User::class,
            'company' => Company::class,
            'company_user' => CompanyUser::class,
            'company_setting' => CompanySetting::class,
            'financial_year' => FinancialYear::class,
            'role' => Role::class,
            'permission' => Permission::class,
            'attachment' => Attachment::class,
            'document_number_format' => DocumentNumberFormat::class,
            'approval_workflow' => ApprovalWorkflow::class,
            'approval_request' => ApprovalRequest::class,
            'project' => Project::class,
            'project_user' => ProjectUser::class,
            'site' => Site::class,
            'client' => Client::class,
            'unit' => Unit::class,
            'material_category' => MaterialCategory::class,
            'tax_rate' => TaxRate::class,
            'material' => Material::class,
            'vendor' => Vendor::class,
            'subcontractor' => Subcontractor::class,
            'labour_trade' => LabourTrade::class,
            'equipment_type' => EquipmentType::class,
            'warehouse' => Warehouse::class,
            'expense_category' => ExpenseCategory::class,
            'boq' => Boq::class,
            'boq_section' => BoqSection::class,
            'boq_item' => BoqItem::class,
            'rate_analysis' => RateAnalysis::class,
            'rate_analysis_item' => RateAnalysisItem::class,
            'project_budget' => ProjectBudget::class,
            'project_budget_line' => ProjectBudgetLine::class,
            'project_milestone' => ProjectMilestone::class,
            'project_task' => ProjectTask::class,
            'task_dependency' => TaskDependency::class,
            'material_request' => MaterialRequest::class,
            'material_request_item' => MaterialRequestItem::class,
            'rfq' => Rfq::class,
            'rfq_item' => RfqItem::class,
            'rfq_vendor' => RfqVendor::class,
            'vendor_quotation' => VendorQuotation::class,
            'vendor_quotation_item' => VendorQuotationItem::class,
            'bid_comparison' => BidComparison::class,
            'purchase_order' => PurchaseOrder::class,
            'purchase_order_item' => PurchaseOrderItem::class,
            'purchase_order_revision' => PurchaseOrderRevision::class,
            'grn' => Grn::class,
            'grn_item' => GrnItem::class,
            'stock_transaction' => StockTransaction::class,
            'material_issue' => MaterialIssue::class,
            'material_issue_item' => MaterialIssueItem::class,
            'stock_transfer' => StockTransfer::class,
            'stock_transfer_item' => StockTransferItem::class,
            'stock_transfer_receipt' => StockTransferReceipt::class,
            'stock_transfer_receipt_item' => StockTransferReceiptItem::class,
            'material_return' => MaterialReturn::class,
            'material_return_item' => MaterialReturnItem::class,
            'stock_adjustment' => StockAdjustment::class,
            'stock_adjustment_item' => StockAdjustmentItem::class,
            'project_cost_entry' => ProjectCostEntry::class,
            'site_diary' => SiteDiary::class,
            'site_diary_work_item' => SiteDiaryWorkItem::class,
            'site_diary_labour' => SiteDiaryLabour::class,
            'site_diary_equipment' => SiteDiaryEquipment::class,
            'site_diary_material' => SiteDiaryMaterial::class,
            'site_diary_photo' => SiteDiaryPhoto::class,
            'dpr' => Dpr::class,
            'dpr_item' => DprItem::class,
            'dpr_labour' => DprLabour::class,
            'dpr_equipment' => DprEquipment::class,
            'dpr_material' => DprMaterial::class,
            'progress_entry' => ProgressEntry::class,
            'labour' => Labour::class,
            'labour_attendance' => LabourAttendance::class,
            'labour_advance' => LabourAdvance::class,
            'labour_payment' => LabourPayment::class,
            'labour_payment_line' => LabourPaymentLine::class,
            'work_order' => WorkOrder::class,
            'work_order_item' => WorkOrderItem::class,
            'work_order_milestone' => WorkOrderMilestone::class,
            'subcontractor_bill' => SubcontractorBill::class,
            'subcontractor_bill_item' => SubcontractorBillItem::class,
            'equipment' => Equipment::class,
            'equipment_assignment' => EquipmentAssignment::class,
            'equipment_usage_log' => EquipmentUsageLog::class,
            'equipment_fuel_log' => EquipmentFuelLog::class,
            'equipment_repair' => EquipmentRepair::class,
            'expense' => Expense::class,
            'petty_cash_account' => PettyCashAccount::class,
            'petty_cash_transaction' => PettyCashTransaction::class,
            'client_invoice' => ClientInvoice::class,
            'client_invoice_item' => ClientInvoiceItem::class,
            'vendor_bill' => VendorBill::class,
            'vendor_bill_item' => VendorBillItem::class,
            'payment' => Payment::class,
            'payment_allocation' => PaymentAllocation::class,
            'tally_connection' => TallyConnection::class,
            'tally_ledger_mapping' => TallyLedgerMapping::class,
            'tally_cost_centre_mapping' => TallyCostCentreMapping::class,
            'tally_master_mapping' => TallyMasterMapping::class,
            'tally_sync_record' => TallySyncRecord::class,
            'retention_release' => RetentionRelease::class,
            'lead' => Lead::class,
            'lead_activity' => LeadActivity::class,
            'quotation' => Quotation::class,
            'quotation_item' => QuotationItem::class,
            'quality_checklist' => QualityChecklist::class,
            'quality_checklist_item' => QualityChecklistItem::class,
            'quality_inspection' => QualityInspection::class,
            'quality_inspection_item' => QualityInspectionItem::class,
            'ncr' => Ncr::class,
            'drawing' => Drawing::class,
            'drawing_revision' => DrawingRevision::class,
            'external_preview_token' => ExternalPreviewToken::class,
            'file_external_access' => FileExternalAccess::class,
            'document_folder' => DocumentFolder::class,
            'document' => Document::class,
            'document_version' => DocumentVersion::class,
            'task_overdue_alert' => TaskOverdueAlert::class,
            'report_export' => ReportExport::class,
        ]);

        // Platform super admin bypasses permission checks (never tenant isolation).
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(Attachment::class, AttachmentPolicy::class);
        Gate::policy(Boq::class, BoqPolicy::class);
        Gate::policy(RateAnalysis::class, RateAnalysisPolicy::class);
        Gate::policy(ProjectBudget::class, ProjectBudgetPolicy::class);
        Gate::policy(ProjectTask::class, ProjectTaskPolicy::class);
        Gate::policy(ProjectMilestone::class, ProjectMilestonePolicy::class);
        Gate::policy(MaterialRequest::class, MaterialRequestPolicy::class);
        Gate::policy(Rfq::class, RfqPolicy::class);
        Gate::policy(VendorQuotation::class, VendorQuotationPolicy::class);
        Gate::policy(BidComparison::class, BidComparisonPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(Grn::class, GrnPolicy::class);
        Gate::policy(MaterialIssue::class, MaterialIssuePolicy::class);
        Gate::policy(StockTransfer::class, StockTransferPolicy::class);
        Gate::policy(MaterialReturn::class, MaterialReturnPolicy::class);
        Gate::policy(StockAdjustment::class, StockAdjustmentPolicy::class);
        Gate::policy(SiteDiary::class, SiteDiaryPolicy::class);
        Gate::policy(Dpr::class, DprPolicy::class);
        Gate::policy(LabourAttendance::class, LabourAttendancePolicy::class);
        Gate::policy(LabourAdvance::class, LabourAdvancePolicy::class);
        Gate::policy(LabourPayment::class, LabourPaymentPolicy::class);
        Gate::policy(WorkOrder::class, WorkOrderPolicy::class);
        Gate::policy(SubcontractorBill::class, SubcontractorBillPolicy::class);
        Gate::policy(EquipmentAssignment::class, EquipmentAssignmentPolicy::class);
        Gate::policy(EquipmentUsageLog::class, EquipmentUsageLogPolicy::class);
        Gate::policy(EquipmentFuelLog::class, EquipmentFuelLogPolicy::class);
        Gate::policy(EquipmentRepair::class, EquipmentRepairPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        Gate::policy(PettyCashAccount::class, PettyCashAccountPolicy::class);
        Gate::policy(ClientInvoice::class, ClientInvoicePolicy::class);
        Gate::policy(VendorBill::class, VendorBillPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(RetentionRelease::class, RetentionReleasePolicy::class);
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Quotation::class, QuotationPolicy::class);
        Gate::policy(QualityChecklist::class, QualityChecklistPolicy::class);
        Gate::policy(QualityInspection::class, QualityInspectionPolicy::class);
        Gate::policy(Ncr::class, NcrPolicy::class);
        Gate::policy(Drawing::class, DrawingPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        foreach (MasterPolicy::PERMISSIONS as $model => $prefix) {
            Gate::policy($model, MasterPolicy::containerKey($prefix));
        }

        Event::listen(ApprovalRequested::class, [SendApprovalNotifications::class, 'handleApprovalRequested']);
        Event::listen(ApprovalCompleted::class, [SendApprovalNotifications::class, 'handleApprovalCompleted']);
        Event::listen(ApprovalRejected::class, [SendApprovalNotifications::class, 'handleApprovalRejected']);
        Event::listen(MaterialRequestSubmitted::class, [SendProcurementNotifications::class, 'handleMaterialRequestSubmitted']);
        Event::listen(PurchaseOrderApproved::class, [SendProcurementNotifications::class, 'handlePurchaseOrderApproved']);
        Event::listen(GrnApproved::class, [SendProcurementNotifications::class, 'handleGrnApproved']);
        Event::listen(GrnApproved::class, [PostGrnStock::class, 'handle']);
        Event::listen(TaskAssigned::class, [SendOperationalNotifications::class, 'handleTaskAssigned']);
        Event::listen(ClientInvoiceCertified::class, [SendOperationalNotifications::class, 'handleClientInvoiceCertified']);
        Event::listen(PaymentReceived::class, [SendOperationalNotifications::class, 'handlePaymentReceived']);
        Event::listen(NcrRaised::class, [SendOperationalNotifications::class, 'handleNcrRaised']);
        Event::listen(MessageSent::class, [SendChatNotification::class, 'handle']);

        // Dashboard KPIs are cached per filter set; any write to their sources invalidates the company's entries.
        $bump = fn (Model $model) => app(DashboardCache::class)->bump((int) ($model->getAttributes()['company_id'] ?? 0));
        foreach ([
            Project::class, ProjectBudget::class, ProjectCostEntry::class, StockTransaction::class, LowStockAlert::class,
            ProgressEntry::class, ProjectTask::class, ClientInvoice::class, Payment::class, RetentionRelease::class,
            VendorBill::class, SubcontractorBill::class, LabourPayment::class, PurchaseOrder::class, WorkOrder::class,
            Grn::class, Expense::class, PettyCashTransaction::class, Ncr::class, QualityInspection::class,
        ] as $model) {
            $model::saved($bump);
            $model::deleted($bump);
        }
        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event) {
            if ($event->connection->transactionLevel() === 0) {
                app(DashboardCache::class)->forgetPending();
            }
        });

        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(5)->by(
            strtolower((string) $request->input('email')).'|'.$request->ip()
        ));
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by(
            ($request->user()?->id ?? 'guest').'|'.$request->ip()
        ));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(600)->by(
            (string) ($request->user()?->id ?? $request->ip())
        ));
        RateLimiter::for('tally-connect', fn (Request $request) => Limit::perMinute(5)->by(
            ($request->user()?->id ?? 'guest').'|'.$request->ip()
        ));
    }
}
