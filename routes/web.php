<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\CompanySettingsController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Boq\BoqController;
use App\Http\Controllers\Boq\BoqExcelController;
use App\Http\Controllers\Boq\BoqSectionController;
use App\Http\Controllers\Boq\ProjectBudgetController;
use App\Http\Controllers\Boq\RateAnalysisController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Core\ApprovalController;
use App\Http\Controllers\Core\AttachmentController;
use App\Http\Controllers\Core\CompanySwitchController;
use App\Http\Controllers\Core\DeviceTokenController;
use App\Http\Controllers\Core\FcmServiceWorkerController;
use App\Http\Controllers\Core\NotificationController;
use App\Http\Controllers\Core\NotificationPreferenceController;
use App\Http\Controllers\Crm\LeadController;
use App\Http\Controllers\Crm\QuotationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\Documents\DocumentFolderController;
use App\Http\Controllers\Documents\DrawingController;
use App\Http\Controllers\Equipment\EquipmentAssignmentController;
use App\Http\Controllers\Equipment\EquipmentFuelController;
use App\Http\Controllers\Equipment\EquipmentRepairController;
use App\Http\Controllers\Equipment\EquipmentUsageController;
use App\Http\Controllers\Files\FileViewerController;
use App\Http\Controllers\Finance\CashFlowController;
use App\Http\Controllers\Finance\ClientInvoiceController;
use App\Http\Controllers\Finance\ExpenseController;
use App\Http\Controllers\Finance\PaymentController;
use App\Http\Controllers\Finance\PettyCashController;
use App\Http\Controllers\Finance\RetentionController;
use App\Http\Controllers\Finance\VendorBillController;
use App\Http\Controllers\Integrations\TallyDocumentController;
use App\Http\Controllers\Integrations\TallyHistoryController;
use App\Http\Controllers\Integrations\TallyMappingController;
use App\Http\Controllers\Integrations\TallySettingsController;
use App\Http\Controllers\Inventory\InventoryController;
use App\Http\Controllers\Inventory\MaterialIssueController;
use App\Http\Controllers\Inventory\MaterialReturnController;
use App\Http\Controllers\Inventory\StockAdjustmentController;
use App\Http\Controllers\Inventory\StockTransferController;
use App\Http\Controllers\Labour\AttendanceController;
use App\Http\Controllers\Labour\LabourCrewController;
use App\Http\Controllers\Labour\LabourPaymentController;
use App\Http\Controllers\Masters\MasterController;
use App\Http\Controllers\Planning\GanttController;
use App\Http\Controllers\Planning\MilestoneController;
use App\Http\Controllers\Planning\ProgressController;
use App\Http\Controllers\Planning\ProjectTaskController;
use App\Http\Controllers\Planning\TaskDependencyController;
use App\Http\Controllers\Platform\CompanyController;
use App\Http\Controllers\Procurement\BidComparisonController;
use App\Http\Controllers\Procurement\GrnController;
use App\Http\Controllers\Procurement\MaterialRequestController;
use App\Http\Controllers\Procurement\PurchaseOrderController;
use App\Http\Controllers\Procurement\RfqController;
use App\Http\Controllers\Procurement\VendorQuotationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectTeamController;
use App\Http\Controllers\Projects\SiteController;
use App\Http\Controllers\Quality\NcrController;
use App\Http\Controllers\Quality\QualityChecklistController;
use App\Http\Controllers\Quality\QualityInspectionController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportExportController;
use App\Http\Controllers\SiteExecution\DprController;
use App\Http\Controllers\SiteExecution\SiteDiaryController;
use App\Http\Controllers\SiteExecution\SiteDiaryPhotoController;
use App\Http\Controllers\Subcontract\SubcontractorBillController;
use App\Http\Controllers\Subcontract\WorkOrderController;
use App\Http\Controllers\Uploads\UploadController;
use App\Support\Masters\MasterRegistry;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::get('/firebase-messaging-sw.js', FcmServiceWorkerController::class)->name('fcm.worker');

Route::middleware(['auth', 'company'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/branding/{kind}', [BrandingController::class, 'show'])->whereIn('kind', ['logo', 'favicon'])->name('company.branding.show');

    Route::get('/chat/directory', [ChatController::class, 'directory'])->name('chat.directory');
    Route::get('/chat/unread', [ChatController::class, 'unread'])->name('chat.unread');
    Route::post('/chat/heartbeat', [ChatController::class, 'heartbeat'])->name('chat.heartbeat');
    Route::post('/chat/direct', [ChatController::class, 'open'])->name('chat.direct');
    Route::get('/chat/attachments/{attachment}', [ChatController::class, 'attachment'])->whereNumber('attachment')->name('chat.attachments.show');
    Route::patch('/chat/messages/{message}', [ChatController::class, 'update'])->whereNumber('message')->name('chat.messages.update');
    Route::delete('/chat/messages/{message}', [ChatController::class, 'destroy'])->whereNumber('message')->name('chat.messages.destroy');
    Route::get('/chat/{conversation}/messages', [ChatController::class, 'messages'])->whereNumber('conversation')->name('chat.messages.index');
    Route::post('/chat/{conversation}/messages', [ChatController::class, 'store'])->whereNumber('conversation')->middleware('throttle:60,1')->name('chat.messages.store');
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{conversation}', [ChatController::class, 'index'])->whereNumber('conversation')->name('chat.show');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::post('/company/switch', CompanySwitchController::class)->middleware('feature:multi_company')->name('company.switch');

    // Projects
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');

    Route::prefix('/projects/{project}')->whereNumber('project')->middleware('project.access')->scopeBindings()->group(function () {
        Route::get('/', [ProjectController::class, 'show'])->name('projects.show');
        Route::get('/edit', [ProjectController::class, 'edit'])->name('projects.edit');
        Route::put('/', [ProjectController::class, 'update'])->name('projects.update');
        Route::patch('/status', [ProjectController::class, 'updateStatus'])->name('projects.status');

        Route::get('/sites', [SiteController::class, 'index'])->name('projects.sites.index');
        Route::post('/sites', [SiteController::class, 'store'])->name('projects.sites.store');
        Route::put('/sites/{site}', [SiteController::class, 'update'])->name('projects.sites.update');
        Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->name('projects.sites.destroy');

        Route::get('/team', [ProjectTeamController::class, 'index'])->name('projects.team.index');
        Route::post('/team', [ProjectTeamController::class, 'store'])->name('projects.team.store');
        Route::put('/team/{member}', [ProjectTeamController::class, 'update'])->name('projects.team.update');
        Route::delete('/team/{member}', [ProjectTeamController::class, 'destroy'])->name('projects.team.destroy');

        // BOQ
        Route::get('/boqs', [BoqController::class, 'index'])->name('projects.boqs.index');
        Route::post('/boqs', [BoqController::class, 'store'])->name('projects.boqs.store');
        Route::get('/boqs/import-template', [BoqExcelController::class, 'template'])->name('projects.boqs.template');
        Route::prefix('/boqs/{boq}')->whereNumber('boq')->name('projects.boqs.')->group(function () {
            Route::get('/', [BoqController::class, 'show'])->name('show');
            Route::put('/', [BoqController::class, 'update'])->name('update');
            Route::delete('/', [BoqController::class, 'destroy'])->name('destroy');
            Route::put('/items', [BoqController::class, 'saveItems'])->name('items.save');
            Route::post('/submit', [BoqController::class, 'submit'])->name('submit');
            Route::post('/revise', [BoqController::class, 'revise'])->name('revise');
            Route::post('/import', [BoqExcelController::class, 'import'])->middleware('throttle:20,1')->name('import');
            Route::get('/export', [BoqExcelController::class, 'export'])->name('export');
            Route::post('/sections', [BoqSectionController::class, 'store'])->name('sections.store');
            Route::put('/sections/{section}', [BoqSectionController::class, 'update'])->whereNumber('section')->name('sections.update');
            Route::delete('/sections/{section}', [BoqSectionController::class, 'destroy'])->whereNumber('section')->name('sections.destroy');
        });

        // Rate analysis
        Route::get('/rate-analyses', [RateAnalysisController::class, 'index'])->name('projects.rate-analyses.index');
        Route::get('/rate-analyses/create', [RateAnalysisController::class, 'create'])->name('projects.rate-analyses.create');
        Route::post('/rate-analyses', [RateAnalysisController::class, 'store'])->name('projects.rate-analyses.store');
        Route::prefix('/rate-analyses/{rateAnalysis}')->whereNumber('rateAnalysis')->name('projects.rate-analyses.')->group(function () {
            Route::get('/', [RateAnalysisController::class, 'show'])->name('show');
            Route::put('/', [RateAnalysisController::class, 'update'])->name('update');
            Route::delete('/', [RateAnalysisController::class, 'destroy'])->name('destroy');
            Route::post('/approve', [RateAnalysisController::class, 'approve'])->name('approve');
        });

        // Budget
        Route::get('/budget', [ProjectBudgetController::class, 'index'])->name('projects.budget.index');
        Route::post('/budget/generate', [ProjectBudgetController::class, 'generate'])->name('projects.budget.generate');
        Route::post('/budget/new-version', [ProjectBudgetController::class, 'newVersion'])->name('projects.budget.new-version');
        Route::post('/budget/lines', [ProjectBudgetController::class, 'storeLine'])->name('projects.budget.lines.store');
        Route::put('/budget/lines/{budgetLine}', [ProjectBudgetController::class, 'updateLine'])->whereNumber('budgetLine')->name('projects.budget.lines.update');
        Route::delete('/budget/lines/{budgetLine}', [ProjectBudgetController::class, 'destroyLine'])->whereNumber('budgetLine')->name('projects.budget.lines.destroy');
        Route::post('/budget/{budget}/approve', [ProjectBudgetController::class, 'approve'])->whereNumber('budget')->name('projects.budget.approve');

        // Planning
        Route::prefix('/planning')->name('projects.planning.')->group(function () {
            Route::get('/tasks', [ProjectTaskController::class, 'index'])->name('tasks.index');
            Route::post('/tasks', [ProjectTaskController::class, 'store'])->name('tasks.store');
            Route::get('/tasks/suggest-wbs', [ProjectTaskController::class, 'suggestWbs'])->name('tasks.suggest-wbs');
            Route::get('/tasks/gantt', [GanttController::class, 'show'])->name('gantt');
            Route::get('/tasks/gantt/data', [GanttController::class, 'data'])->name('gantt.data');
            Route::put('/tasks/{task}', [ProjectTaskController::class, 'update'])->whereNumber('task')->name('tasks.update');
            Route::delete('/tasks/{task}', [ProjectTaskController::class, 'destroy'])->whereNumber('task')->name('tasks.destroy');
            Route::patch('/tasks/{task}/status', [ProjectTaskController::class, 'updateStatus'])->whereNumber('task')->name('tasks.status');
            Route::post('/tasks/{task}/dependencies', [TaskDependencyController::class, 'store'])->whereNumber('task')->name('tasks.dependencies.store');
            Route::delete('/tasks/{task}/dependencies/{dependency}', [TaskDependencyController::class, 'destroy'])
                ->whereNumber(['task', 'dependency'])->name('tasks.dependencies.destroy');

            Route::get('/milestones', [MilestoneController::class, 'index'])->name('milestones.index');
            Route::post('/milestones', [MilestoneController::class, 'store'])->name('milestones.store');
            Route::put('/milestones/{milestone}', [MilestoneController::class, 'update'])->whereNumber('milestone')->name('milestones.update');
            Route::patch('/milestones/{milestone}/complete', [MilestoneController::class, 'complete'])->whereNumber('milestone')->name('milestones.complete');
            Route::delete('/milestones/{milestone}', [MilestoneController::class, 'destroy'])->whereNumber('milestone')->name('milestones.destroy');
        });
        Route::get('/progress', ProgressController::class)->name('projects.progress');
        Route::get('/progress/boq', [ProgressController::class, 'boq'])->name('projects.progress.boq');

        // Site execution: site diaries
        Route::get('/site-diaries', [SiteDiaryController::class, 'index'])->name('projects.site-diaries.index');
        Route::get('/site-diaries/create', [SiteDiaryController::class, 'create'])->name('projects.site-diaries.create');
        Route::post('/site-diaries', [SiteDiaryController::class, 'store'])->name('projects.site-diaries.store');
        Route::prefix('/site-diaries/{siteDiary}')->whereNumber('siteDiary')->name('projects.site-diaries.')->group(function () {
            Route::get('/', [SiteDiaryController::class, 'show'])->name('show');
            Route::get('/edit', [SiteDiaryController::class, 'edit'])->name('edit');
            Route::put('/', [SiteDiaryController::class, 'update'])->name('update');
            Route::delete('/', [SiteDiaryController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [SiteDiaryController::class, 'submit'])->name('submit');
            Route::post('/review', [SiteDiaryController::class, 'review'])->name('review');
            Route::post('/approve', [SiteDiaryController::class, 'approve'])->name('approve');
            Route::post('/reject', [SiteDiaryController::class, 'reject'])->name('reject');
            Route::post('/photos', [SiteDiaryPhotoController::class, 'store'])->middleware('throttle:60,1')->name('photos.store');
            Route::get('/photos/{photo}', [SiteDiaryPhotoController::class, 'show'])->whereNumber('photo')->name('photos.show');
            Route::get('/photos/{photo}/thumb', [SiteDiaryPhotoController::class, 'thumb'])->whereNumber('photo')->name('photos.thumb');
            Route::delete('/photos/{photo}', [SiteDiaryPhotoController::class, 'destroy'])->whereNumber('photo')->name('photos.destroy');
        });

        // Site execution: daily progress reports
        Route::get('/dprs', [DprController::class, 'index'])->name('projects.dprs.index');
        Route::get('/dprs/create', [DprController::class, 'create'])->name('projects.dprs.create');
        Route::post('/dprs', [DprController::class, 'store'])->name('projects.dprs.store');
        Route::prefix('/dprs/{dpr}')->whereNumber('dpr')->name('projects.dprs.')->group(function () {
            Route::get('/', [DprController::class, 'show'])->name('show');
            Route::get('/edit', [DprController::class, 'edit'])->name('edit');
            Route::put('/', [DprController::class, 'update'])->name('update');
            Route::delete('/', [DprController::class, 'destroy'])->name('destroy');
            Route::post('/refresh', [DprController::class, 'refresh'])->name('refresh');
            Route::post('/submit', [DprController::class, 'submit'])->name('submit');
            Route::post('/reopen', [DprController::class, 'reopen'])->name('reopen');
            Route::get('/pdf', [DprController::class, 'pdf'])->name('pdf');
        });

        // Labour: crew & advances, attendance, payments
        Route::get('/labour', [LabourCrewController::class, 'index'])->name('projects.labour.index');
        Route::post('/labour/advances', [LabourCrewController::class, 'storeAdvance'])->name('projects.labour.advances.store');
        Route::delete('/labour/advances/{advance}', [LabourCrewController::class, 'destroyAdvance'])->whereNumber('advance')->name('projects.labour.advances.destroy');
        Route::get('/attendance', [AttendanceController::class, 'index'])->name('projects.attendance.index');
        Route::post('/attendance', [AttendanceController::class, 'store'])->name('projects.attendance.store');
        Route::post('/attendance/approve', [AttendanceController::class, 'approve'])->name('projects.attendance.approve');
        Route::post('/attendance/{attendance}/unapprove', [AttendanceController::class, 'unapprove'])->whereNumber('attendance')->name('projects.attendance.unapprove');
        Route::delete('/attendance/{attendance}', [AttendanceController::class, 'destroy'])->whereNumber('attendance')->name('projects.attendance.destroy');
        Route::get('/labour-payments', [LabourPaymentController::class, 'index'])->name('projects.labour-payments.index');
        Route::post('/labour-payments', [LabourPaymentController::class, 'store'])->name('projects.labour-payments.store');
        Route::prefix('/labour-payments/{labourPayment}')->whereNumber('labourPayment')->name('projects.labour-payments.')->group(function () {
            Route::get('/', [LabourPaymentController::class, 'show'])->name('show');
            Route::put('/', [LabourPaymentController::class, 'update'])->name('update');
            Route::delete('/', [LabourPaymentController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [LabourPaymentController::class, 'submit'])->name('submit');
            Route::post('/approve', [LabourPaymentController::class, 'approve'])->name('approve');
            Route::post('/send-back', [LabourPaymentController::class, 'sendBack'])->name('send-back');
            Route::post('/mark-paid', [LabourPaymentController::class, 'markPaid'])->name('mark-paid');
        });

        // Subcontract: work orders, bills
        Route::get('/work-orders', [WorkOrderController::class, 'index'])->name('projects.work-orders.index');
        Route::get('/work-orders/create', [WorkOrderController::class, 'create'])->name('projects.work-orders.create');
        Route::post('/work-orders', [WorkOrderController::class, 'store'])->name('projects.work-orders.store');
        Route::prefix('/work-orders/{workOrder}')->whereNumber('workOrder')->name('projects.work-orders.')->group(function () {
            Route::get('/', [WorkOrderController::class, 'show'])->name('show');
            Route::get('/edit', [WorkOrderController::class, 'edit'])->name('edit');
            Route::put('/', [WorkOrderController::class, 'update'])->name('update');
            Route::delete('/', [WorkOrderController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [WorkOrderController::class, 'submit'])->name('submit');
            Route::post('/complete', [WorkOrderController::class, 'complete'])->name('complete');
            Route::post('/close', [WorkOrderController::class, 'close'])->name('close');
            Route::post('/cancel', [WorkOrderController::class, 'cancel'])->name('cancel');
            Route::put('/milestones', [WorkOrderController::class, 'milestones'])->name('milestones');
        });
        Route::get('/subcontractor-bills', [SubcontractorBillController::class, 'index'])->name('projects.subcontractor-bills.index');
        Route::get('/subcontractor-bills/create', [SubcontractorBillController::class, 'create'])->name('projects.subcontractor-bills.create');
        Route::post('/subcontractor-bills', [SubcontractorBillController::class, 'store'])->name('projects.subcontractor-bills.store');
        Route::prefix('/subcontractor-bills/{subcontractorBill}')->whereNumber('subcontractorBill')->name('projects.subcontractor-bills.')->group(function () {
            Route::get('/', [SubcontractorBillController::class, 'show'])->name('show');
            Route::get('/edit', [SubcontractorBillController::class, 'edit'])->name('edit');
            Route::put('/', [SubcontractorBillController::class, 'update'])->name('update');
            Route::delete('/', [SubcontractorBillController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [SubcontractorBillController::class, 'submit'])->name('submit');
            Route::put('/certify', [SubcontractorBillController::class, 'adjust'])->name('adjust');
            Route::post('/reverse', [SubcontractorBillController::class, 'reverse'])->name('reverse');
        });

        // Equipment: assignments, usage, fuel, repairs
        Route::get('/equipment-assignments', [EquipmentAssignmentController::class, 'index'])->name('projects.equipment-assignments.index');
        Route::post('/equipment-assignments', [EquipmentAssignmentController::class, 'store'])->name('projects.equipment-assignments.store');
        Route::put('/equipment-assignments/{equipmentAssignment}', [EquipmentAssignmentController::class, 'update'])->whereNumber('equipmentAssignment')->name('projects.equipment-assignments.update');
        Route::post('/equipment-assignments/{equipmentAssignment}/return', [EquipmentAssignmentController::class, 'returnEquipment'])->whereNumber('equipmentAssignment')->name('projects.equipment-assignments.return');
        Route::get('/equipment-usage', [EquipmentUsageController::class, 'index'])->name('projects.equipment-usage.index');
        Route::post('/equipment-usage', [EquipmentUsageController::class, 'store'])->name('projects.equipment-usage.store');
        Route::post('/equipment-usage/post', [EquipmentUsageController::class, 'post'])->name('projects.equipment-usage.post');
        Route::put('/equipment-usage/{equipmentUsageLog}', [EquipmentUsageController::class, 'update'])->whereNumber('equipmentUsageLog')->name('projects.equipment-usage.update');
        Route::delete('/equipment-usage/{equipmentUsageLog}', [EquipmentUsageController::class, 'destroy'])->whereNumber('equipmentUsageLog')->name('projects.equipment-usage.destroy');
        Route::post('/equipment-usage/{equipmentUsageLog}/reverse', [EquipmentUsageController::class, 'reverse'])->whereNumber('equipmentUsageLog')->name('projects.equipment-usage.reverse');
        Route::get('/equipment-fuel', [EquipmentFuelController::class, 'index'])->name('projects.equipment-fuel.index');
        Route::post('/equipment-fuel', [EquipmentFuelController::class, 'store'])->name('projects.equipment-fuel.store');
        Route::put('/equipment-fuel/{equipmentFuelLog}', [EquipmentFuelController::class, 'update'])->whereNumber('equipmentFuelLog')->name('projects.equipment-fuel.update');
        Route::delete('/equipment-fuel/{equipmentFuelLog}', [EquipmentFuelController::class, 'destroy'])->whereNumber('equipmentFuelLog')->name('projects.equipment-fuel.destroy');
        Route::get('/equipment-repairs', [EquipmentRepairController::class, 'index'])->name('projects.equipment-repairs.index');
        Route::post('/equipment-repairs', [EquipmentRepairController::class, 'store'])->name('projects.equipment-repairs.store');
        Route::put('/equipment-repairs/{equipmentRepair}', [EquipmentRepairController::class, 'update'])->whereNumber('equipmentRepair')->name('projects.equipment-repairs.update');
        Route::post('/equipment-repairs/{equipmentRepair}/complete', [EquipmentRepairController::class, 'complete'])->whereNumber('equipmentRepair')->name('projects.equipment-repairs.complete');
        Route::post('/equipment-repairs/{equipmentRepair}/cancel', [EquipmentRepairController::class, 'cancel'])->whereNumber('equipmentRepair')->name('projects.equipment-repairs.cancel');

        // Procurement: material requests
        Route::get('/material-requests', [MaterialRequestController::class, 'index'])->name('projects.material-requests.index');
        Route::get('/material-requests/create', [MaterialRequestController::class, 'create'])->name('projects.material-requests.create');
        Route::post('/material-requests', [MaterialRequestController::class, 'store'])->name('projects.material-requests.store');
        Route::prefix('/material-requests/{materialRequest}')->whereNumber('materialRequest')->name('projects.material-requests.')->group(function () {
            Route::get('/', [MaterialRequestController::class, 'show'])->name('show');
            Route::get('/edit', [MaterialRequestController::class, 'edit'])->name('edit');
            Route::put('/', [MaterialRequestController::class, 'update'])->name('update');
            Route::delete('/', [MaterialRequestController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [MaterialRequestController::class, 'submit'])->name('submit');
            Route::post('/cancel', [MaterialRequestController::class, 'cancel'])->name('cancel');
        });

        // Procurement: RFQs, vendor quotations, bid comparison
        Route::get('/rfqs', [RfqController::class, 'index'])->name('projects.rfqs.index');
        Route::get('/rfqs/create', [RfqController::class, 'create'])->name('projects.rfqs.create');
        Route::post('/rfqs', [RfqController::class, 'store'])->name('projects.rfqs.store');
        Route::prefix('/rfqs/{rfq}')->whereNumber('rfq')->name('projects.rfqs.')->group(function () {
            Route::get('/', [RfqController::class, 'show'])->name('show');
            Route::get('/edit', [RfqController::class, 'edit'])->name('edit');
            Route::put('/', [RfqController::class, 'update'])->name('update');
            Route::delete('/', [RfqController::class, 'destroy'])->name('destroy');
            Route::put('/vendors', [RfqController::class, 'vendors'])->name('vendors');
            Route::post('/send', [RfqController::class, 'send'])->name('send');
            Route::post('/close', [RfqController::class, 'close'])->name('close');
            Route::post('/cancel', [RfqController::class, 'cancel'])->name('cancel');

            Route::get('/quotations/create', [VendorQuotationController::class, 'create'])->name('quotations.create');
            Route::post('/quotations', [VendorQuotationController::class, 'store'])->name('quotations.store');
            Route::get('/quotations/{quotation}/edit', [VendorQuotationController::class, 'edit'])->whereNumber('quotation')->name('quotations.edit');
            Route::put('/quotations/{quotation}', [VendorQuotationController::class, 'update'])->whereNumber('quotation')->name('quotations.update');
            Route::delete('/quotations/{quotation}', [VendorQuotationController::class, 'destroy'])->whereNumber('quotation')->name('quotations.destroy');

            Route::get('/comparison', [BidComparisonController::class, 'show'])->name('comparison');
            Route::put('/comparison', [BidComparisonController::class, 'save'])->name('comparison.save');
            Route::post('/comparison/submit', [BidComparisonController::class, 'submit'])->name('comparison.submit');
            Route::post('/comparison/approve', [BidComparisonController::class, 'approve'])->name('comparison.approve');
            Route::post('/comparison/reject', [BidComparisonController::class, 'reject'])->name('comparison.reject');

            Route::post('/purchase-order', [PurchaseOrderController::class, 'storeFromRfq'])->name('purchase-order');
        });

        // Procurement: purchase orders
        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('projects.purchase-orders.index');
        Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('projects.purchase-orders.create');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('projects.purchase-orders.store');
        Route::prefix('/purchase-orders/{purchaseOrder}')->whereNumber('purchaseOrder')->name('projects.purchase-orders.')->group(function () {
            Route::get('/', [PurchaseOrderController::class, 'show'])->name('show');
            Route::get('/edit', [PurchaseOrderController::class, 'edit'])->name('edit');
            Route::put('/', [PurchaseOrderController::class, 'update'])->name('update');
            Route::delete('/', [PurchaseOrderController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [PurchaseOrderController::class, 'submit'])->name('submit');
            Route::post('/amend', [PurchaseOrderController::class, 'amend'])->name('amend');
            Route::post('/cancel', [PurchaseOrderController::class, 'cancel'])->name('cancel');
            Route::post('/close', [PurchaseOrderController::class, 'close'])->name('close');
            Route::get('/pdf', [PurchaseOrderController::class, 'pdf'])->name('pdf');
        });

        // Procurement: goods receipts
        Route::get('/grns', [GrnController::class, 'index'])->name('projects.grns.index');
        Route::get('/grns/create', [GrnController::class, 'create'])->name('projects.grns.create');
        Route::post('/grns', [GrnController::class, 'store'])->name('projects.grns.store');
        Route::prefix('/grns/{grn}')->whereNumber('grn')->name('projects.grns.')->group(function () {
            Route::get('/', [GrnController::class, 'show'])->name('show');
            Route::get('/edit', [GrnController::class, 'edit'])->name('edit');
            Route::put('/', [GrnController::class, 'update'])->name('update');
            Route::delete('/', [GrnController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [GrnController::class, 'submit'])->name('submit');
        });

        // Inventory: stock overview and ledger (read only)
        Route::get('/inventory', [InventoryController::class, 'index'])->name('projects.inventory.index');
        Route::get('/inventory/ledger', [InventoryController::class, 'ledger'])->name('projects.inventory.ledger');

        // Inventory: material issues
        Route::get('/material-issues', [MaterialIssueController::class, 'index'])->name('projects.material-issues.index');
        Route::get('/material-issues/create', [MaterialIssueController::class, 'create'])->name('projects.material-issues.create');
        Route::post('/material-issues', [MaterialIssueController::class, 'store'])->name('projects.material-issues.store');
        Route::prefix('/material-issues/{materialIssue}')->whereNumber('materialIssue')->name('projects.material-issues.')->group(function () {
            Route::get('/', [MaterialIssueController::class, 'show'])->name('show');
            Route::get('/edit', [MaterialIssueController::class, 'edit'])->name('edit');
            Route::put('/', [MaterialIssueController::class, 'update'])->name('update');
            Route::delete('/', [MaterialIssueController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [MaterialIssueController::class, 'submit'])->name('submit');
            Route::post('/cancel', [MaterialIssueController::class, 'cancel'])->name('cancel');
        });

        // Inventory: stock transfers
        Route::get('/stock-transfers', [StockTransferController::class, 'index'])->name('projects.stock-transfers.index');
        Route::get('/stock-transfers/create', [StockTransferController::class, 'create'])->name('projects.stock-transfers.create');
        Route::post('/stock-transfers', [StockTransferController::class, 'store'])->name('projects.stock-transfers.store');
        Route::prefix('/stock-transfers/{stockTransfer}')->whereNumber('stockTransfer')->name('projects.stock-transfers.')->group(function () {
            Route::get('/', [StockTransferController::class, 'show'])->name('show');
            Route::get('/edit', [StockTransferController::class, 'edit'])->name('edit');
            Route::put('/', [StockTransferController::class, 'update'])->name('update');
            Route::delete('/', [StockTransferController::class, 'destroy'])->name('destroy');
            Route::post('/dispatch', [StockTransferController::class, 'dispatch'])->name('dispatch');
            Route::post('/receive', [StockTransferController::class, 'receive'])->name('receive');
            Route::post('/cancel', [StockTransferController::class, 'cancel'])->name('cancel');
            Route::post('/close-short', [StockTransferController::class, 'closeShort'])->name('close-short');
        });

        // Inventory: material returns (site to store, to vendor)
        Route::get('/material-returns', [MaterialReturnController::class, 'index'])->name('projects.material-returns.index');
        Route::get('/material-returns/create', [MaterialReturnController::class, 'create'])->name('projects.material-returns.create');
        Route::post('/material-returns', [MaterialReturnController::class, 'store'])->name('projects.material-returns.store');
        Route::prefix('/material-returns/{materialReturn}')->whereNumber('materialReturn')->name('projects.material-returns.')->group(function () {
            Route::get('/', [MaterialReturnController::class, 'show'])->name('show');
            Route::get('/edit', [MaterialReturnController::class, 'edit'])->name('edit');
            Route::put('/', [MaterialReturnController::class, 'update'])->name('update');
            Route::delete('/', [MaterialReturnController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [MaterialReturnController::class, 'submit'])->name('submit');
            Route::post('/cancel', [MaterialReturnController::class, 'cancel'])->name('cancel');
        });

        // Inventory: stock adjustments
        Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index'])->name('projects.stock-adjustments.index');
        Route::get('/stock-adjustments/create', [StockAdjustmentController::class, 'create'])->name('projects.stock-adjustments.create');
        Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])->name('projects.stock-adjustments.store');
        Route::prefix('/stock-adjustments/{stockAdjustment}')->whereNumber('stockAdjustment')->name('projects.stock-adjustments.')->group(function () {
            Route::get('/', [StockAdjustmentController::class, 'show'])->name('show');
            Route::get('/edit', [StockAdjustmentController::class, 'edit'])->name('edit');
            Route::put('/', [StockAdjustmentController::class, 'update'])->name('update');
            Route::delete('/', [StockAdjustmentController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [StockAdjustmentController::class, 'submit'])->name('submit');
            Route::post('/approve', [StockAdjustmentController::class, 'approve'])->name('approve');
            Route::post('/reject', [StockAdjustmentController::class, 'reject'])->name('reject');
            Route::post('/cancel', [StockAdjustmentController::class, 'cancel'])->name('cancel');
        });

        // Finance: expenses, petty cash
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('projects.expenses.index');
        Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('projects.expenses.create');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('projects.expenses.store');
        Route::prefix('/expenses/{expense}')->whereNumber('expense')->name('projects.expenses.')->group(function () {
            Route::get('/', [ExpenseController::class, 'show'])->name('show');
            Route::get('/edit', [ExpenseController::class, 'edit'])->name('edit');
            Route::put('/', [ExpenseController::class, 'update'])->name('update');
            Route::delete('/', [ExpenseController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [ExpenseController::class, 'submit'])->name('submit');
            Route::post('/mark-paid', [ExpenseController::class, 'markPaid'])->name('mark-paid');
            Route::post('/reverse', [ExpenseController::class, 'reverse'])->name('reverse');
        });
        Route::get('/petty-cash', [PettyCashController::class, 'index'])->name('projects.petty-cash.index');
        Route::post('/petty-cash', [PettyCashController::class, 'store'])->name('projects.petty-cash.store');
        Route::prefix('/petty-cash/{pettyCashAccount}')->whereNumber('pettyCashAccount')->name('projects.petty-cash.')->group(function () {
            Route::get('/', [PettyCashController::class, 'show'])->name('show');
            Route::put('/', [PettyCashController::class, 'update'])->name('update');
            Route::post('/fund', [PettyCashController::class, 'fund'])->name('fund');
            Route::post('/return', [PettyCashController::class, 'returnCash'])->name('return');
        });

        // Finance: client RA bills, vendor bills
        Route::get('/ra-bills', [ClientInvoiceController::class, 'index'])->name('projects.ra-bills.index');
        Route::get('/ra-bills/create', [ClientInvoiceController::class, 'create'])->name('projects.ra-bills.create');
        Route::post('/ra-bills', [ClientInvoiceController::class, 'store'])->name('projects.ra-bills.store');
        Route::prefix('/ra-bills/{clientInvoice}')->whereNumber('clientInvoice')->name('projects.ra-bills.')->group(function () {
            Route::get('/', [ClientInvoiceController::class, 'show'])->name('show');
            Route::get('/edit', [ClientInvoiceController::class, 'edit'])->name('edit');
            Route::put('/', [ClientInvoiceController::class, 'update'])->name('update');
            Route::delete('/', [ClientInvoiceController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [ClientInvoiceController::class, 'submit'])->name('submit');
            Route::get('/pdf', [ClientInvoiceController::class, 'pdf'])->name('pdf');
        });
        Route::get('/vendor-bills', [VendorBillController::class, 'index'])->name('projects.vendor-bills.index');
        Route::get('/vendor-bills/create', [VendorBillController::class, 'create'])->name('projects.vendor-bills.create');
        Route::post('/vendor-bills', [VendorBillController::class, 'store'])->name('projects.vendor-bills.store');
        Route::prefix('/vendor-bills/{vendorBill}')->whereNumber('vendorBill')->name('projects.vendor-bills.')->group(function () {
            Route::get('/', [VendorBillController::class, 'show'])->name('show');
            Route::get('/edit', [VendorBillController::class, 'edit'])->name('edit');
            Route::put('/', [VendorBillController::class, 'update'])->name('update');
            Route::delete('/', [VendorBillController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [VendorBillController::class, 'submit'])->name('submit');
        });

        // Finance: receipts / payments, retention releases, cash flow
        Route::get('/payments', [PaymentController::class, 'index'])->name('projects.payments.index');
        Route::get('/payments/create', [PaymentController::class, 'create'])->name('projects.payments.create');
        Route::post('/payments', [PaymentController::class, 'store'])->name('projects.payments.store');
        Route::prefix('/payments/{payment}')->whereNumber('payment')->name('projects.payments.')->group(function () {
            Route::get('/', [PaymentController::class, 'show'])->name('show');
            Route::get('/edit', [PaymentController::class, 'edit'])->name('edit');
            Route::put('/', [PaymentController::class, 'update'])->name('update');
            Route::delete('/', [PaymentController::class, 'destroy'])->name('destroy');
            Route::post('/approve', [PaymentController::class, 'approve'])->name('approve');
            Route::post('/cancel', [PaymentController::class, 'cancel'])->name('cancel');
        });
        Route::get('/retention', [RetentionController::class, 'index'])->name('projects.retention.index');
        Route::post('/retention', [RetentionController::class, 'store'])->name('projects.retention.store');
        Route::prefix('/retention/{retentionRelease}')->whereNumber('retentionRelease')->name('projects.retention.')->group(function () {
            Route::put('/', [RetentionController::class, 'update'])->name('update');
            Route::delete('/', [RetentionController::class, 'destroy'])->name('destroy');
            Route::post('/submit', [RetentionController::class, 'submit'])->name('submit');
        });
        Route::get('/cash-flow', [CashFlowController::class, 'project'])->name('projects.cash-flow');

        // Quality: inspections and NCRs
        Route::get('/quality/inspections', [QualityInspectionController::class, 'index'])->name('projects.inspections.index');
        Route::get('/quality/inspections/create', [QualityInspectionController::class, 'create'])->name('projects.inspections.create');
        Route::post('/quality/inspections', [QualityInspectionController::class, 'store'])->name('projects.inspections.store');
        Route::prefix('/quality/inspections/{qualityInspection}')->whereNumber('qualityInspection')->name('projects.inspections.')->group(function () {
            Route::get('/', [QualityInspectionController::class, 'show'])->name('show');
            Route::get('/edit', [QualityInspectionController::class, 'edit'])->name('edit');
            Route::put('/', [QualityInspectionController::class, 'update'])->name('update');
            Route::delete('/', [QualityInspectionController::class, 'destroy'])->name('destroy');
            Route::post('/schedule', [QualityInspectionController::class, 'schedule'])->name('schedule');
            Route::post('/record', [QualityInspectionController::class, 'record'])->name('record');
            Route::post('/complete', [QualityInspectionController::class, 'complete'])->name('complete');
        });
        Route::get('/quality/ncrs', [NcrController::class, 'index'])->name('projects.ncrs.index');
        Route::get('/quality/ncrs/create', [NcrController::class, 'create'])->name('projects.ncrs.create');
        Route::post('/quality/ncrs', [NcrController::class, 'store'])->name('projects.ncrs.store');
        Route::prefix('/quality/ncrs/{ncr}')->whereNumber('ncr')->name('projects.ncrs.')->group(function () {
            Route::get('/', [NcrController::class, 'show'])->name('show');
            Route::get('/edit', [NcrController::class, 'edit'])->name('edit');
            Route::put('/', [NcrController::class, 'update'])->name('update');
            Route::delete('/', [NcrController::class, 'destroy'])->name('destroy');
            Route::post('/start', [NcrController::class, 'start'])->name('start');
            Route::post('/resolve', [NcrController::class, 'resolve'])->name('resolve');
            Route::post('/verify', [NcrController::class, 'verify'])->name('verify');
            Route::post('/reopen', [NcrController::class, 'reopen'])->name('reopen');
            Route::post('/close', [NcrController::class, 'close'])->name('close');
        });

        // Drawings: register, immutable revisions, review / approval, secure preview / download
        Route::get('/drawings', [DrawingController::class, 'index'])->name('projects.drawings.index');
        Route::get('/drawings/create', [DrawingController::class, 'create'])->name('projects.drawings.create');
        Route::post('/drawings', [DrawingController::class, 'store'])->middleware('throttle:30,1')->name('projects.drawings.store');
        Route::prefix('/drawings/{drawing}')->whereNumber('drawing')->name('projects.drawings.')->group(function () {
            Route::get('/', [DrawingController::class, 'show'])->name('show');
            Route::put('/', [DrawingController::class, 'update'])->name('update');
            Route::post('/revisions', [DrawingController::class, 'storeRevision'])->middleware('throttle:30,1')->name('revisions.store');
            Route::prefix('/revisions/{revision}')->whereNumber('revision')->name('revisions.')->group(function () {
                Route::post('/submit', [DrawingController::class, 'submit'])->name('submit');
                Route::post('/review', [DrawingController::class, 'review'])->name('review');
                Route::post('/approve', [DrawingController::class, 'approve'])->name('approve');
                Route::post('/reject', [DrawingController::class, 'reject'])->name('reject');
                Route::delete('/', [DrawingController::class, 'withdraw'])->name('withdraw');
                Route::get('/download', [DrawingController::class, 'download'])->name('download');
                Route::get('/preview', [DrawingController::class, 'preview'])->name('preview');
            });
        });

        // Documents: folders, controlled documents, immutable versions
        Route::get('/documents', [DocumentController::class, 'index'])->name('projects.documents.index');
        Route::get('/documents/create', [DocumentController::class, 'create'])->name('projects.documents.create');
        Route::post('/documents', [DocumentController::class, 'store'])->middleware('throttle:30,1')->name('projects.documents.store');
        Route::post('/documents/folders', [DocumentFolderController::class, 'store'])->name('projects.documents.folders.store');
        Route::put('/documents/folders/{documentFolder}', [DocumentFolderController::class, 'update'])->whereNumber('documentFolder')->name('projects.documents.folders.update');
        Route::delete('/documents/folders/{documentFolder}', [DocumentFolderController::class, 'destroy'])->whereNumber('documentFolder')->name('projects.documents.folders.destroy');
        Route::prefix('/documents/{document}')->whereNumber('document')->name('projects.documents.')->group(function () {
            Route::get('/', [DocumentController::class, 'show'])->name('show');
            Route::put('/', [DocumentController::class, 'update'])->name('update');
            Route::delete('/', [DocumentController::class, 'destroy'])->name('destroy');
            Route::post('/publish', [DocumentController::class, 'publish'])->name('publish');
            Route::post('/archive', [DocumentController::class, 'archive'])->name('archive');
            Route::post('/restore', [DocumentController::class, 'restore'])->name('restore');
            Route::post('/versions', [DocumentController::class, 'storeVersion'])->middleware('throttle:30,1')->name('versions.store');
            Route::get('/versions/{version}/download', [DocumentController::class, 'download'])->whereNumber('version')->name('versions.download');
            Route::get('/versions/{version}/preview', [DocumentController::class, 'preview'])->whereNumber('version')->name('versions.preview');
        });

        // Project reports (same definitions, scoped to this project)
        Route::get('/reports', [ReportController::class, 'projectIndex'])->name('reports.project-index');
        Route::get('/reports/{report}', [ReportController::class, 'project'])->where('report', '[a-z0-9-]+')->name('reports.project');
        Route::get('/reports/{report}/export', [ReportController::class, 'projectExport'])->where('report', '[a-z0-9-]+')->middleware('throttle:20,1')->name('reports.project-export');
    });

    // Reports (company scope = projects the user can see)
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->where('report', '[a-z0-9-]+')->name('reports.show');
    Route::get('/reports/{report}/export', [ReportController::class, 'export'])->where('report', '[a-z0-9-]+')->middleware('throttle:20,1')->name('reports.export');
    Route::get('/report-exports/{reportExport}/download', [ReportExportController::class, 'download'])->whereNumber('reportExport')->name('reports.exports.download');

    // Quality checklist templates (company masters)
    Route::prefix('/quality/checklists')->name('quality.checklists.')->group(function () {
        Route::get('/', [QualityChecklistController::class, 'index'])->name('index');
        Route::get('/create', [QualityChecklistController::class, 'create'])->name('create');
        Route::post('/', [QualityChecklistController::class, 'store'])->name('store');
        Route::get('/{checklist}', [QualityChecklistController::class, 'show'])->whereNumber('checklist')->name('show');
        Route::put('/{checklist}', [QualityChecklistController::class, 'update'])->whereNumber('checklist')->name('update');
        Route::delete('/{checklist}', [QualityChecklistController::class, 'destroy'])->whereNumber('checklist')->name('destroy');
    });

    // Finance: company-wide cash flow and outstanding (projects the user can see)
    Route::get('/finance/cash-flow', [CashFlowController::class, 'company'])->name('finance.cash-flow');

    // CRM: leads, activities, quotations, conversion to project
    Route::prefix('/crm')->name('crm.')->group(function () {
        Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
        Route::get('/leads/create', [LeadController::class, 'create'])->name('leads.create');
        Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
        Route::prefix('/leads/{lead}')->whereNumber('lead')->name('leads.')->group(function () {
            Route::get('/', [LeadController::class, 'show'])->name('show');
            Route::get('/edit', [LeadController::class, 'edit'])->name('edit');
            Route::put('/', [LeadController::class, 'update'])->name('update');
            Route::delete('/', [LeadController::class, 'destroy'])->name('destroy');
            Route::patch('/status', [LeadController::class, 'status'])->name('status');
            Route::post('/activities', [LeadController::class, 'storeActivity'])->name('activities.store');
        });
        Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::get('/quotations/create', [QuotationController::class, 'create'])->name('quotations.create');
        Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store');
        Route::prefix('/quotations/{quotation}')->whereNumber('quotation')->name('quotations.')->group(function () {
            Route::get('/', [QuotationController::class, 'show'])->name('show');
            Route::get('/edit', [QuotationController::class, 'edit'])->name('edit');
            Route::put('/', [QuotationController::class, 'update'])->name('update');
            Route::delete('/', [QuotationController::class, 'destroy'])->name('destroy');
            Route::post('/send', [QuotationController::class, 'send'])->name('send');
            Route::post('/accept', [QuotationController::class, 'accept'])->name('accept');
            Route::post('/reject', [QuotationController::class, 'reject'])->name('reject');
            Route::post('/expire', [QuotationController::class, 'expire'])->name('expire');
            Route::post('/revise', [QuotationController::class, 'revise'])->name('revise');
            Route::post('/convert', [QuotationController::class, 'convert'])->name('convert');
        });
    });

    // Masters (items, units, vendors, clients, ...) share one controller driven by MasterRegistry.
    Route::prefix('/masters/{master}')->name('masters.')
        ->whereIn('master', MasterRegistry::slugs())
        ->group(function () {
            Route::get('/', [MasterController::class, 'index'])->name('index');
            Route::post('/', [MasterController::class, 'store'])->name('store');
            Route::get('/{record}', [MasterController::class, 'show'])->whereNumber('record')->name('show');
            Route::put('/{record}', [MasterController::class, 'update'])->whereNumber('record')->name('update');
            Route::delete('/{record}', [MasterController::class, 'destroy'])->whereNumber('record')->name('destroy');
        });

    // Chunked uploads. Each request carries one chunk, never the whole file.
    Route::post('/uploads', [UploadController::class, 'store'])->middleware('throttle:uploads')->name('uploads.store');
    Route::get('/uploads/{upload}', [UploadController::class, 'show'])->middleware('throttle:uploads')->name('uploads.show');
    Route::post('/uploads/{upload}/chunks/{number}', [UploadController::class, 'chunk'])->whereNumber('number')->middleware('throttle:uploads')->name('uploads.chunks.store');
    Route::post('/uploads/{upload}/complete', [UploadController::class, 'complete'])->middleware('throttle:uploads')->name('uploads.complete');
    Route::delete('/uploads/{upload}', [UploadController::class, 'destroy'])->middleware('throttle:uploads')->name('uploads.cancel');

    // Universal private file viewer. Every request re-checks the parent record.
    Route::get('/files/{source}/{id}', [FileViewerController::class, 'show'])->whereIn('source', ['attachment', 'document_version', 'drawing_revision', 'chat', 'site_photo'])->whereNumber('id')->name('files.show');
    Route::get('/files/{source}/{id}/preview', [FileViewerController::class, 'preview'])->whereIn('source', ['attachment', 'document_version', 'drawing_revision', 'chat', 'site_photo'])->whereNumber('id')->name('files.preview');
    Route::get('/files/{source}/{id}/stream', [FileViewerController::class, 'stream'])->whereIn('source', ['attachment', 'document_version', 'drawing_revision', 'chat', 'site_photo'])->whereNumber('id')->name('files.stream');
    Route::get('/files/{source}/{id}/download', [FileViewerController::class, 'download'])->whereIn('source', ['attachment', 'document_version', 'drawing_revision', 'chat', 'site_photo'])->whereNumber('id')->name('files.download');

    // Attachments
    Route::post('/attachments', [AttachmentController::class, 'store'])->middleware('throttle:30,1')->name('attachments.store');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    // Approvals inbox
    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::prefix('/approvals/{approvalRequest}')->name('approvals.')->group(function () {
        Route::post('/approve', [ApprovalController::class, 'approve'])->name('approve');
        Route::post('/reject', [ApprovalController::class, 'reject'])->name('reject');
        Route::post('/send-back', [ApprovalController::class, 'sendBack'])->name('send-back');
        Route::post('/cancel', [ApprovalController::class, 'cancel'])->name('cancel');
    });

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->whereUuid('notification')->name('notifications.read');
    Route::get('/notifications/preferences', [NotificationPreferenceController::class, 'edit'])->name('notifications.preferences');
    Route::put('/notifications/preferences', [NotificationPreferenceController::class, 'update'])->name('notifications.preferences.update');
    Route::post('/devices', [DeviceTokenController::class, 'store'])->middleware('throttle:30,1')->name('devices.store');
    Route::delete('/devices', [DeviceTokenController::class, 'destroy'])->name('devices.destroy');

    Route::prefix('/integrations/tally')->name('integrations.tally.')->group(function () {
        Route::get('/', [TallySettingsController::class, 'edit'])->name('edit');
        Route::put('/', [TallySettingsController::class, 'update'])->name('update');
        Route::post('/test', [TallySettingsController::class, 'test'])->middleware('throttle:tally-connect')->name('test');
        Route::post('/masters', [TallySettingsController::class, 'syncMasters'])->middleware('throttle:tally-connect')->name('masters');
        Route::post('/pending', [TallySettingsController::class, 'syncPending'])->middleware('throttle:tally-connect')->name('pending');
        Route::get('/mappings', [TallyMappingController::class, 'edit'])->name('mappings');
        Route::put('/mappings', [TallyMappingController::class, 'updateSystems'])->name('mappings.update');
        Route::post('/mappings/parties', [TallyMappingController::class, 'storeParty'])->name('mappings.parties');
        Route::post('/mappings/cost-centres', [TallyMappingController::class, 'storeCostCentre'])->name('mappings.cost-centres');
        Route::get('/history', [TallyHistoryController::class, 'index'])->name('history');
        Route::get('/history/{record}', [TallyHistoryController::class, 'show'])->name('history.show');
        Route::post('/history/{record}/retry', [TallyHistoryController::class, 'retry'])->name('history.retry');
        Route::get('/reconciliation', [TallyHistoryController::class, 'reconciliation'])->name('reconciliation');
        Route::get('/documents/{type}/{id}/preview', [TallyDocumentController::class, 'preview'])->where('type', '[a-z_]+')->name('documents.preview');
        Route::post('/documents/{type}/{id}/sync', [TallyDocumentController::class, 'sync'])->where('type', '[a-z_]+')->name('documents.sync');
    });

    // Company administration
    Route::prefix('/admin')->name('admin.')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

        Route::get('/company', [CompanySettingsController::class, 'edit'])->name('company.edit');
        Route::put('/company', [CompanySettingsController::class, 'update'])->name('company.update');
        Route::put('/company/procurement', [CompanySettingsController::class, 'updateProcurement'])->name('company.procurement');
        Route::post('/company/logo', [BrandingController::class, 'storeLogo'])->name('company.logo.store');
        Route::delete('/company/logo', [BrandingController::class, 'destroyLogo'])->name('company.logo.destroy');
        Route::post('/company/favicon', [BrandingController::class, 'storeFavicon'])->name('company.favicon.store');
        Route::delete('/company/favicon', [BrandingController::class, 'destroyFavicon'])->name('company.favicon.destroy');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    });

    // Platform (super admin). Hidden while features.multi_company is off; the controllers stay.
    Route::prefix('/platform')->middleware('feature:multi_company')->name('platform.')->group(function () {
        Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
        Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
        Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    });
});

require __DIR__.'/auth.php';
