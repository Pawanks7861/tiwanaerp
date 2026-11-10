<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BoqController;
use App\Http\Controllers\Api\V1\DataImportController;
use App\Http\Controllers\Api\V1\Documents\DocumentApiController;
use App\Http\Controllers\Api\V1\Documents\DocumentFolderApiController;
use App\Http\Controllers\Api\V1\Documents\DrawingController;
use App\Http\Controllers\Api\V1\Quality\QualityChecklistApiController;
use App\Http\Controllers\Api\V1\Site\NcrApiController;
use App\Http\Controllers\Api\V1\Site\QualityInspectionApiController;
use App\Http\Controllers\Api\V1\Site\QualitySummaryApiController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\Site\ApprovalController;
use App\Http\Controllers\Api\V1\Site\AttendanceController;
use App\Http\Controllers\Api\V1\Site\LabourAdvanceApiController;
use App\Http\Controllers\Api\V1\Site\LabourPaymentApiController;
use App\Http\Controllers\Api\V1\Site\LabourRegisterController;
use App\Http\Controllers\Api\V1\Site\SubcontractorApiController;
use App\Http\Controllers\Api\V1\Site\SubcontractorBillApiController;
use App\Http\Controllers\Api\V1\Site\WorkOrderApiController;
use App\Http\Controllers\Api\V1\Site\GRNController;
use App\Http\Controllers\Api\V1\Site\InventoryController;
use App\Http\Controllers\Api\V1\Site\LabourController;
use App\Http\Controllers\Api\V1\Site\MaterialIssueController;
use App\Http\Controllers\Api\V1\Site\MaterialRequisitionController;
use App\Http\Controllers\Api\V1\Site\MaterialReturnController;
use App\Http\Controllers\Api\V1\Site\ProcurementSummaryController;
use App\Http\Controllers\Api\V1\Site\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Site\DprController;
use App\Http\Controllers\Api\V1\Site\EquipmentFuelApiController;
use App\Http\Controllers\Api\V1\Site\EquipmentRegisterController;
use App\Http\Controllers\Api\V1\Site\EquipmentRepairApiController;
use App\Http\Controllers\Api\V1\Site\EquipmentSummaryController;
use App\Http\Controllers\Api\V1\Site\EquipmentUsageApiController;
use App\Http\Controllers\Api\V1\Site\ClientRaBillApiController;
use App\Http\Controllers\Api\V1\Site\ExpenseApiController;
use App\Http\Controllers\Api\V1\Site\FinanceSummaryApiController;
use App\Http\Controllers\Api\V1\Site\GatePassApiController;
use App\Http\Controllers\Api\V1\Site\PaymentApiController;
use App\Http\Controllers\Api\V1\Site\RetentionReleaseApiController;
use App\Http\Controllers\Api\V1\Site\VendorBillApiController;
use App\Http\Controllers\Api\V1\Site\VendorLedgerApiController;
use App\Http\Controllers\Api\V1\Site\MilestoneApiController;
use App\Http\Controllers\Api\V1\Site\ProgressController;
use App\Http\Controllers\Api\V1\Site\SiteDiaryController;
use App\Http\Controllers\Api\V1\Site\SiteSummaryController;
use App\Http\Controllers\Api\V1\Site\StockAdjustmentController;
use App\Http\Controllers\Api\V1\Site\StockTransferController;
use App\Http\Controllers\Api\V1\Site\TaskController;
use App\Http\Controllers\Core\DeviceTokenController;
use App\Http\Controllers\Uploads\UploadController;
use App\Services\Files\FileSourceResolver;
use Illuminate\Support\Facades\Route;

// Mobile / integration API. Token auth (Sanctum); company chosen with the X-Company-Id header.
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login')->name('auth.login');

    Route::middleware(['auth:sanctum', 'company', 'throttle:api'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/devices', [DeviceTokenController::class, 'store'])->name('devices.store');
        Route::delete('/devices', [DeviceTokenController::class, 'destroy'])->name('devices.destroy');

        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');

        Route::get('/lookups/clients', [LookupController::class, 'clients'])->name('lookups.clients');
        Route::get('/lookups/project-managers', [LookupController::class, 'projectManagers'])->name('lookups.project-managers');
        Route::get('/lookups/states', [LookupController::class, 'states'])->name('lookups.states');
        Route::get('/lookups/materials', [LookupController::class, 'materials'])->name('lookups.materials');
        Route::get('/lookups/vendors', [LookupController::class, 'vendors'])->name('lookups.vendors');
        Route::get('/lookups/warehouses', [LookupController::class, 'warehouses'])->name('lookups.warehouses');
        Route::get('/lookups/tax-rates', [LookupController::class, 'taxRates'])->name('lookups.tax-rates');
        Route::get('/lookups/units', [LookupController::class, 'units'])->name('lookups.units');
        Route::get('/lookups/grn-extra-reasons', [LookupController::class, 'grnExtraReasons'])->name('lookups.grn-extra-reasons');
        Route::get('/lookups/adjustment-reasons', [LookupController::class, 'adjustmentReasons'])->name('lookups.adjustment-reasons');
        Route::get('/lookups/material-return-types', [LookupController::class, 'materialReturnTypes'])->name('lookups.material-return-types');
        Route::get('/lookups/sites', [LookupController::class, 'sites'])->name('lookups.sites');
        Route::get('/lookups/tasks', [LookupController::class, 'tasks'])->name('lookups.tasks');
        Route::get('/lookups/boq-items', [LookupController::class, 'boqItems'])->name('lookups.boq-items');
        Route::get('/lookups/labour-trades', [LookupController::class, 'labourTrades'])->name('lookups.labour-trades');
        Route::get('/lookups/labours', [LookupController::class, 'labours'])->name('lookups.labours');
        Route::get('/lookups/subcontractors', [LookupController::class, 'subcontractors'])->name('lookups.subcontractors');
        Route::get('/labours/{labour}', [LabourRegisterController::class, 'show'])->whereNumber('labour')->name('labours.show');
        Route::post('/labours', [LabourRegisterController::class, 'store'])->middleware('throttle:20,1')->name('labours.store');
        Route::match(['put', 'patch'], '/labours/{labour}', [LabourRegisterController::class, 'update'])->whereNumber('labour')->name('labours.update');
        Route::delete('/labour/advances/{advance}', [LabourAdvanceApiController::class, 'destroy'])->whereNumber('advance')->name('labour.advances.destroy');
        Route::get('/subcontractors/{subcontractor}', [SubcontractorApiController::class, 'show'])->whereNumber('subcontractor')->name('subcontractors.show');
        Route::get('/lookups/equipment-types', [LookupController::class, 'equipmentTypes'])->name('lookups.equipment-types');
        Route::get('/lookups/equipment', [LookupController::class, 'equipment'])->name('lookups.equipment');
        Route::get('/lookups/expense-categories', [LookupController::class, 'expenseCategories'])->name('lookups.expense-categories');
        Route::get('/lookups/quality-checklists', [LookupController::class, 'qualityChecklists'])->name('lookups.quality-checklists');
        Route::get('/quality/checklists/{checklist}', [QualityChecklistApiController::class, 'show'])
            ->whereNumber('checklist')
            ->name('quality.checklists.show');

        Route::get('/equipment', [EquipmentRegisterController::class, 'index'])->name('equipment.index');
        Route::post('/equipment', [EquipmentRegisterController::class, 'store'])->middleware('throttle:20,1')->name('equipment.store');
        Route::get('/equipment/{equipment}', [EquipmentRegisterController::class, 'show'])->whereNumber('equipment')->name('equipment.show');
        Route::match(['put', 'patch'], '/equipment/{equipment}', [EquipmentRegisterController::class, 'update'])->whereNumber('equipment')->name('equipment.update');
        Route::post('/equipment/{equipment}/location', [EquipmentRegisterController::class, 'changeLocation'])->whereNumber('equipment')->middleware('throttle:20,1')->name('equipment.location');
        Route::get('/equipment/{equipment}/location-history', [EquipmentRegisterController::class, 'locationHistory'])->whereNumber('equipment')->name('equipment.location-history');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        Route::prefix('/files/{source}/{id}')
            ->whereNumber('id')
            ->whereIn('source', FileSourceResolver::SOURCES)
            ->name('files.')
            ->group(function () {
                Route::get('/', [FileController::class, 'show'])->name('show');
                Route::get('/preview', [FileController::class, 'preview'])->name('preview');
                Route::get('/stream', [FileController::class, 'stream'])->name('stream');
                Route::get('/download', [FileController::class, 'download'])->name('download');
            });

        Route::post('/attachments', [AttachmentController::class, 'store'])->middleware('throttle:30,1')->name('attachments.store');
        Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->whereNumber('attachment')->name('attachments.destroy');

        Route::get('/imports/{module}/meta', [DataImportController::class, 'meta'])->where('module', '[a-z0-9_.-]+')->name('imports.meta');
        Route::post('/imports', [DataImportController::class, 'store'])->middleware('throttle:20,1')->name('imports.store');
        Route::get('/imports/{import}', [DataImportController::class, 'show'])->whereNumber('import')->name('imports.show');
        Route::post('/imports/{import}/sheet', [DataImportController::class, 'selectSheet'])->whereNumber('import')->middleware('throttle:30,1')->name('imports.sheet');
        Route::post('/imports/{import}/validate', [DataImportController::class, 'validateMapping'])->whereNumber('import')->middleware('throttle:30,1')->name('imports.validate');
        Route::post('/imports/{import}/confirm', [DataImportController::class, 'confirm'])->whereNumber('import')->middleware('throttle:10,1')->name('imports.confirm');
        Route::get('/imports/{import}/file', [DataImportController::class, 'downloadFile'])->whereNumber('import')->name('imports.file');

        Route::post('/uploads', [UploadController::class, 'store'])->middleware('throttle:uploads')->name('uploads.store');
        Route::get('/uploads/{upload}', [UploadController::class, 'show'])->middleware('throttle:uploads')->name('uploads.show');
        Route::post('/uploads/{upload}/chunks/{number}', [UploadController::class, 'chunk'])->whereNumber('number')->middleware('throttle:uploads')->name('uploads.chunks.store');
        Route::post('/uploads/{upload}/complete', [UploadController::class, 'complete'])->middleware('throttle:uploads')->name('uploads.complete');
        Route::delete('/uploads/{upload}', [UploadController::class, 'destroy'])->middleware('throttle:uploads')->name('uploads.cancel');

        Route::middleware('project.access')->whereNumber('project')->group(function () {
            Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::get('/projects/{project}/setup-status', [ProjectController::class, 'setupStatus'])->name('projects.setup-status');
            Route::get('/projects/{project}/procurement/summary', [ProcurementSummaryController::class, 'show'])
                ->name('projects.procurement.summary');
            Route::get('/projects/{project}/attendance', [AttendanceController::class, 'index'])
                ->name('projects.attendance.index');
            Route::post('/projects/{project}/attendance/approve', [AttendanceController::class, 'approve'])
                ->middleware('throttle:10,1')
                ->name('projects.attendance.approve');
            Route::post('/projects/{project}/attendance/{attendance}/unapprove', [AttendanceController::class, 'unapprove'])
                ->whereNumber('attendance')
                ->middleware('throttle:10,1')
                ->name('projects.attendance.unapprove');
            Route::delete('/projects/{project}/attendance/{attendance}', [AttendanceController::class, 'destroy'])
                ->whereNumber('attendance')
                ->name('projects.attendance.destroy');
            Route::get('/projects/{project}/equipment/summary', [EquipmentSummaryController::class, 'show'])
                ->name('projects.equipment.summary');
            Route::get('/projects/{project}/equipment', [EquipmentRegisterController::class, 'projectIndex'])
                ->name('projects.equipment.index');
            Route::get('/projects/{project}/equipment-fuel', [EquipmentFuelApiController::class, 'index'])
                ->name('projects.equipment-fuel.index');
            Route::post('/projects/{project}/equipment-fuel', [EquipmentFuelApiController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.equipment-fuel.store');
            Route::get('/projects/{project}/equipment-fuel/{equipmentFuelLog}', [EquipmentFuelApiController::class, 'show'])
                ->whereNumber('equipmentFuelLog')
                ->name('projects.equipment-fuel.show');
            Route::match(['put', 'patch'], '/projects/{project}/equipment-fuel/{equipmentFuelLog}', [EquipmentFuelApiController::class, 'update'])
                ->whereNumber('equipmentFuelLog')
                ->name('projects.equipment-fuel.update');
            Route::delete('/projects/{project}/equipment-fuel/{equipmentFuelLog}', [EquipmentFuelApiController::class, 'destroy'])
                ->whereNumber('equipmentFuelLog')
                ->name('projects.equipment-fuel.destroy');
            Route::get('/projects/{project}/equipment-usage', [EquipmentUsageApiController::class, 'index'])
                ->name('projects.equipment-usage.index');
            Route::post('/projects/{project}/equipment-usage', [EquipmentUsageApiController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.equipment-usage.store');
            Route::get('/projects/{project}/equipment-usage/{equipmentUsageLog}', [EquipmentUsageApiController::class, 'show'])
                ->whereNumber('equipmentUsageLog')
                ->name('projects.equipment-usage.show');
            Route::match(['put', 'patch'], '/projects/{project}/equipment-usage/{equipmentUsageLog}', [EquipmentUsageApiController::class, 'update'])
                ->whereNumber('equipmentUsageLog')
                ->name('projects.equipment-usage.update');
            Route::delete('/projects/{project}/equipment-usage/{equipmentUsageLog}', [EquipmentUsageApiController::class, 'destroy'])
                ->whereNumber('equipmentUsageLog')
                ->name('projects.equipment-usage.destroy');
            Route::post('/projects/{project}/equipment-usage/post', [EquipmentUsageApiController::class, 'post'])
                ->middleware('throttle:10,1')
                ->name('projects.equipment-usage.post');
            Route::post('/projects/{project}/equipment-usage/{equipmentUsageLog}/reverse', [EquipmentUsageApiController::class, 'reverse'])
                ->whereNumber('equipmentUsageLog')
                ->middleware('throttle:10,1')
                ->name('projects.equipment-usage.reverse');
            Route::get('/projects/{project}/equipment-repairs', [EquipmentRepairApiController::class, 'index'])
                ->name('projects.equipment-repairs.index');
            Route::post('/projects/{project}/equipment-repairs', [EquipmentRepairApiController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.equipment-repairs.store');
            Route::get('/projects/{project}/equipment-repairs/{equipmentRepair}', [EquipmentRepairApiController::class, 'show'])
                ->whereNumber('equipmentRepair')
                ->name('projects.equipment-repairs.show');
            Route::match(['put', 'patch'], '/projects/{project}/equipment-repairs/{equipmentRepair}', [EquipmentRepairApiController::class, 'update'])
                ->whereNumber('equipmentRepair')
                ->name('projects.equipment-repairs.update');
            Route::post('/projects/{project}/equipment-repairs/{equipmentRepair}/complete', [EquipmentRepairApiController::class, 'complete'])
                ->whereNumber('equipmentRepair')
                ->middleware('throttle:10,1')
                ->name('projects.equipment-repairs.complete');
            Route::post('/projects/{project}/equipment-repairs/{equipmentRepair}/cancel', [EquipmentRepairApiController::class, 'cancel'])
                ->whereNumber('equipmentRepair')
                ->middleware('throttle:10,1')
                ->name('projects.equipment-repairs.cancel');
            Route::get('/projects/{project}/gate-passes/summary', [GatePassApiController::class, 'summary'])
                ->name('projects.gate-passes.summary');
            Route::get('/projects/{project}/gate-passes', [GatePassApiController::class, 'index'])
                ->name('projects.gate-passes.index');
            Route::post('/projects/{project}/gate-passes', [GatePassApiController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.gate-passes.store');
            Route::get('/projects/{project}/gate-passes/{gatePass}', [GatePassApiController::class, 'show'])
                ->whereNumber('gatePass')
                ->name('projects.gate-passes.show');
            Route::match(['put', 'patch'], '/projects/{project}/gate-passes/{gatePass}', [GatePassApiController::class, 'update'])
                ->whereNumber('gatePass')
                ->name('projects.gate-passes.update');
            Route::delete('/projects/{project}/gate-passes/{gatePass}', [GatePassApiController::class, 'destroy'])
                ->whereNumber('gatePass')
                ->name('projects.gate-passes.destroy');
            Route::post('/projects/{project}/gate-passes/{gatePass}/submit', [GatePassApiController::class, 'submit'])
                ->whereNumber('gatePass')
                ->middleware('throttle:10,1')
                ->name('projects.gate-passes.submit');
            Route::post('/projects/{project}/gate-passes/{gatePass}/approve', [GatePassApiController::class, 'approve'])
                ->whereNumber('gatePass')
                ->middleware('throttle:10,1')
                ->name('projects.gate-passes.approve');
            Route::post('/projects/{project}/gate-passes/{gatePass}/reject', [GatePassApiController::class, 'reject'])
                ->whereNumber('gatePass')
                ->middleware('throttle:10,1')
                ->name('projects.gate-passes.reject');
            Route::post('/projects/{project}/gate-passes/{gatePass}/verify', [GatePassApiController::class, 'verify'])
                ->whereNumber('gatePass')
                ->middleware('throttle:10,1')
                ->name('projects.gate-passes.verify');
            Route::post('/projects/{project}/gate-passes/{gatePass}/returned', [GatePassApiController::class, 'returned'])
                ->whereNumber('gatePass')
                ->middleware('throttle:10,1')
                ->name('projects.gate-passes.returned');
            Route::get('/projects/{project}/gate-passes/{gatePass}/pdf/view', [GatePassApiController::class, 'pdf'])
                ->whereNumber('gatePass')
                ->name('projects.gate-passes.pdf.view');
            Route::get('/projects/{project}/gate-passes/{gatePass}/pdf/download', [GatePassApiController::class, 'pdf'])
                ->whereNumber('gatePass')
                ->name('projects.gate-passes.pdf.download');

            Route::get('/projects/{project}/labour/summary', [LabourController::class, 'summary'])
                ->name('projects.labour.summary');
            Route::get('/projects/{project}/labour', [LabourController::class, 'index'])
                ->name('projects.labour.index');
            Route::get('/projects/{project}/labour/crew', [LabourRegisterController::class, 'crew'])
                ->name('projects.labour.crew');
            Route::get('/projects/{project}/labour/roster', [LabourController::class, 'roster'])
                ->name('projects.labour.roster');
            Route::get('/projects/{project}/labour/attendance', [LabourController::class, 'attendance'])
                ->name('projects.labour.attendance');
            Route::post('/projects/{project}/labour/attendance', [LabourController::class, 'storeAttendance'])
                ->middleware('throttle:10,1')
                ->name('projects.labour.attendance.store');
            Route::get('/projects/{project}/labour/advances', [LabourAdvanceApiController::class, 'index'])
                ->name('projects.labour.advances.index');
            Route::post('/projects/{project}/labour/advances', [LabourAdvanceApiController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('projects.labour.advances.store');
            Route::get('/projects/{project}/labour/advances/{advance}', [LabourAdvanceApiController::class, 'show'])
                ->whereNumber('advance')
                ->name('projects.labour.advances.show');
            Route::get('/projects/{project}/labour/payments', [LabourController::class, 'payments'])
                ->name('projects.labour.payments');
            Route::get('/projects/{project}/labour-payments/prefill', [LabourPaymentApiController::class, 'prefill'])
                ->name('projects.labour-payments.prefill');
            Route::post('/projects/{project}/labour-payments', [LabourPaymentApiController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('projects.labour-payments.store');
            Route::get('/projects/{project}/labour-payments/{labourPayment}', [LabourPaymentApiController::class, 'show'])
                ->whereNumber('labourPayment')
                ->name('projects.labour-payments.show');
            Route::match(['put', 'patch'], '/projects/{project}/labour-payments/{labourPayment}', [LabourPaymentApiController::class, 'update'])
                ->whereNumber('labourPayment')
                ->name('projects.labour-payments.update');
            Route::delete('/projects/{project}/labour-payments/{labourPayment}', [LabourPaymentApiController::class, 'destroy'])
                ->whereNumber('labourPayment')
                ->name('projects.labour-payments.destroy');
            Route::post('/projects/{project}/labour-payments/{labourPayment}/submit', [LabourPaymentApiController::class, 'submit'])
                ->whereNumber('labourPayment')
                ->middleware('throttle:10,1')
                ->name('projects.labour-payments.submit');
            Route::post('/projects/{project}/labour-payments/{labourPayment}/approve', [LabourPaymentApiController::class, 'approve'])
                ->whereNumber('labourPayment')
                ->middleware('throttle:10,1')
                ->name('projects.labour-payments.approve');
            Route::post('/projects/{project}/labour-payments/{labourPayment}/send-back', [LabourPaymentApiController::class, 'sendBack'])
                ->whereNumber('labourPayment')
                ->middleware('throttle:10,1')
                ->name('projects.labour-payments.send-back');
            Route::post('/projects/{project}/labour-payments/{labourPayment}/mark-paid', [LabourPaymentApiController::class, 'markPaid'])
                ->whereNumber('labourPayment')
                ->middleware('throttle:10,1')
                ->name('projects.labour-payments.mark-paid');
            Route::get('/projects/{project}/subcontractors', [SubcontractorApiController::class, 'index'])
                ->name('projects.subcontractors.index');
            Route::get('/projects/{project}/work-orders', [WorkOrderApiController::class, 'index'])
                ->name('projects.work-orders.index');
            Route::post('/projects/{project}/work-orders', [WorkOrderApiController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('projects.work-orders.store');
            Route::get('/projects/{project}/work-orders/{workOrder}', [WorkOrderApiController::class, 'show'])
                ->whereNumber('workOrder')
                ->name('projects.work-orders.show');
            Route::match(['put', 'patch'], '/projects/{project}/work-orders/{workOrder}', [WorkOrderApiController::class, 'update'])
                ->whereNumber('workOrder')
                ->name('projects.work-orders.update');
            Route::delete('/projects/{project}/work-orders/{workOrder}', [WorkOrderApiController::class, 'destroy'])
                ->whereNumber('workOrder')
                ->name('projects.work-orders.destroy');
            Route::post('/projects/{project}/work-orders/{workOrder}/submit', [WorkOrderApiController::class, 'submit'])
                ->whereNumber('workOrder')
                ->middleware('throttle:10,1')
                ->name('projects.work-orders.submit');
            Route::post('/projects/{project}/work-orders/{workOrder}/complete', [WorkOrderApiController::class, 'complete'])
                ->whereNumber('workOrder')
                ->middleware('throttle:10,1')
                ->name('projects.work-orders.complete');
            Route::post('/projects/{project}/work-orders/{workOrder}/close', [WorkOrderApiController::class, 'close'])
                ->whereNumber('workOrder')
                ->middleware('throttle:10,1')
                ->name('projects.work-orders.close');
            Route::post('/projects/{project}/work-orders/{workOrder}/cancel', [WorkOrderApiController::class, 'cancel'])
                ->whereNumber('workOrder')
                ->middleware('throttle:10,1')
                ->name('projects.work-orders.cancel');
            Route::match(['put', 'patch'], '/projects/{project}/work-orders/{workOrder}/milestones', [WorkOrderApiController::class, 'updateMilestones'])
                ->whereNumber('workOrder')
                ->middleware('throttle:10,1')
                ->name('projects.work-orders.milestones.update');
            Route::get('/projects/{project}/work-orders/{workOrder}/billable-lines', [WorkOrderApiController::class, 'billableLines'])
                ->whereNumber('workOrder')
                ->name('projects.work-orders.billable-lines');
            Route::get('/projects/{project}/subcontractor-bills', [SubcontractorBillApiController::class, 'index'])
                ->name('projects.subcontractor-bills.index');
            Route::get('/projects/{project}/subcontractor-bills/prefill/{workOrder}', [SubcontractorBillApiController::class, 'prefill'])
                ->whereNumber('workOrder')
                ->name('projects.subcontractor-bills.prefill');
            Route::post('/projects/{project}/subcontractor-bills', [SubcontractorBillApiController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('projects.subcontractor-bills.store');
            Route::get('/projects/{project}/subcontractor-bills/{subcontractorBill}', [SubcontractorBillApiController::class, 'show'])
                ->whereNumber('subcontractorBill')
                ->name('projects.subcontractor-bills.show');
            Route::match(['put', 'patch'], '/projects/{project}/subcontractor-bills/{subcontractorBill}', [SubcontractorBillApiController::class, 'update'])
                ->whereNumber('subcontractorBill')
                ->name('projects.subcontractor-bills.update');
            Route::delete('/projects/{project}/subcontractor-bills/{subcontractorBill}', [SubcontractorBillApiController::class, 'destroy'])
                ->whereNumber('subcontractorBill')
                ->name('projects.subcontractor-bills.destroy');
            Route::post('/projects/{project}/subcontractor-bills/{subcontractorBill}/submit', [SubcontractorBillApiController::class, 'submit'])
                ->whereNumber('subcontractorBill')
                ->middleware('throttle:10,1')
                ->name('projects.subcontractor-bills.submit');
            Route::match(['put', 'patch'], '/projects/{project}/subcontractor-bills/{subcontractorBill}/adjust', [SubcontractorBillApiController::class, 'adjust'])
                ->whereNumber('subcontractorBill')
                ->middleware('throttle:10,1')
                ->name('projects.subcontractor-bills.adjust');
            Route::post('/projects/{project}/subcontractor-bills/{subcontractorBill}/reverse', [SubcontractorBillApiController::class, 'reverse'])
                ->whereNumber('subcontractorBill')
                ->middleware('throttle:10,1')
                ->name('projects.subcontractor-bills.reverse');

            // Material requisitions (Phase 1 name; same as web material-requests)
            Route::get('/projects/{project}/material-requisitions', [MaterialRequisitionController::class, 'index'])
                ->name('projects.material-requisitions.index');
            Route::post('/projects/{project}/material-requisitions', [MaterialRequisitionController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.material-requisitions.store');
            Route::get('/projects/{project}/material-requisitions/{material_requisition}', [MaterialRequisitionController::class, 'show'])
                ->whereNumber('material_requisition')
                ->name('projects.material-requisitions.show');
            Route::match(['put', 'patch'], '/projects/{project}/material-requisitions/{materialRequest}', [MaterialRequisitionController::class, 'update'])
                ->whereNumber('materialRequest')
                ->name('projects.material-requisitions.update');
            Route::post('/projects/{project}/material-requisitions/{materialRequest}/submit', [MaterialRequisitionController::class, 'submit'])
                ->whereNumber('materialRequest')
                ->middleware('throttle:10,1')
                ->name('projects.material-requisitions.submit');
            Route::post('/projects/{project}/material-requisitions/{materialRequest}/cancel', [MaterialRequisitionController::class, 'cancel'])
                ->whereNumber('materialRequest')
                ->middleware('throttle:10,1')
                ->name('projects.material-requisitions.cancel');

            // Purchase orders
            Route::get('/projects/{project}/purchase-orders', [PurchaseOrderController::class, 'index'])
                ->name('projects.purchase-orders.index');
            Route::get('/projects/{project}/purchase-orders/eligible-material-requests', [PurchaseOrderController::class, 'eligibleMaterialRequests'])
                ->name('projects.purchase-orders.eligible-material-requests');
            Route::post('/projects/{project}/purchase-orders', [PurchaseOrderController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.purchase-orders.store');
            Route::get('/projects/{project}/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
                ->whereNumber('purchaseOrder')
                ->name('projects.purchase-orders.show');
            Route::match(['put', 'patch'], '/projects/{project}/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])
                ->whereNumber('purchaseOrder')
                ->name('projects.purchase-orders.update');
            Route::post('/projects/{project}/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])
                ->whereNumber('purchaseOrder')
                ->middleware('throttle:10,1')
                ->name('projects.purchase-orders.submit');
            Route::post('/projects/{project}/purchase-orders/{purchaseOrder}/amend', [PurchaseOrderController::class, 'amend'])
                ->whereNumber('purchaseOrder')
                ->middleware('throttle:10,1')
                ->name('projects.purchase-orders.amend');
            Route::post('/projects/{project}/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
                ->whereNumber('purchaseOrder')
                ->middleware('throttle:10,1')
                ->name('projects.purchase-orders.cancel');
            Route::post('/projects/{project}/purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])
                ->whereNumber('purchaseOrder')
                ->middleware('throttle:10,1')
                ->name('projects.purchase-orders.close');
            Route::get('/projects/{project}/purchase-orders/{purchaseOrder}/pdf/view', [PurchaseOrderController::class, 'pdf'])
                ->whereNumber('purchaseOrder')
                ->name('projects.purchase-orders.pdf.view');
            Route::get('/projects/{project}/purchase-orders/{purchaseOrder}/pdf/download', [PurchaseOrderController::class, 'pdf'])
                ->whereNumber('purchaseOrder')
                ->name('projects.purchase-orders.pdf.download');

            Route::get('/projects/{project}/finance/summary', [FinanceSummaryApiController::class, 'show'])
                ->name('projects.finance.summary');
            Route::get('/projects/{project}/finance/reconciliation', [VendorLedgerApiController::class, 'reconciliation'])
                ->name('projects.finance.reconciliation');
            Route::get('/projects/{project}/vendors/{vendor}/ledger', [VendorLedgerApiController::class, 'ledger'])
                ->whereNumber('vendor')
                ->name('projects.vendors.ledger');

            Route::get('/projects/{project}/client-ra-bills/prefill', [ClientRaBillApiController::class, 'prefill'])
                ->name('projects.client-ra-bills.prefill');
            Route::get('/projects/{project}/client-ra-bills', [ClientRaBillApiController::class, 'index'])
                ->name('projects.client-ra-bills.index');
            Route::post('/projects/{project}/client-ra-bills', [ClientRaBillApiController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.client-ra-bills.store');
            Route::get('/projects/{project}/client-ra-bills/{clientInvoice}', [ClientRaBillApiController::class, 'show'])
                ->whereNumber('clientInvoice')
                ->name('projects.client-ra-bills.show');
            Route::match(['put', 'patch'], '/projects/{project}/client-ra-bills/{clientInvoice}', [ClientRaBillApiController::class, 'update'])
                ->whereNumber('clientInvoice')
                ->name('projects.client-ra-bills.update');
            Route::delete('/projects/{project}/client-ra-bills/{clientInvoice}', [ClientRaBillApiController::class, 'destroy'])
                ->whereNumber('clientInvoice')
                ->name('projects.client-ra-bills.destroy');
            Route::post('/projects/{project}/client-ra-bills/{clientInvoice}/submit', [ClientRaBillApiController::class, 'submit'])
                ->whereNumber('clientInvoice')
                ->middleware('throttle:10,1')
                ->name('projects.client-ra-bills.submit');
            Route::get('/projects/{project}/client-ra-bills/{clientInvoice}/pdf/view', [ClientRaBillApiController::class, 'pdf'])
                ->whereNumber('clientInvoice')
                ->name('projects.client-ra-bills.pdf.view');
            Route::get('/projects/{project}/client-ra-bills/{clientInvoice}/pdf/download', [ClientRaBillApiController::class, 'pdf'])
                ->whereNumber('clientInvoice')
                ->name('projects.client-ra-bills.pdf.download');

            Route::get('/projects/{project}/payments/open-payables', [PaymentApiController::class, 'openPayables'])
                ->name('projects.payments.open-payables');
            Route::get('/projects/{project}/payments', [PaymentApiController::class, 'index'])
                ->name('projects.payments.index');
            Route::post('/projects/{project}/payments', [PaymentApiController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.payments.store');
            Route::get('/projects/{project}/payments/{payment}', [PaymentApiController::class, 'show'])
                ->whereNumber('payment')
                ->name('projects.payments.show');
            Route::match(['put', 'patch'], '/projects/{project}/payments/{payment}', [PaymentApiController::class, 'update'])
                ->whereNumber('payment')
                ->name('projects.payments.update');
            Route::delete('/projects/{project}/payments/{payment}', [PaymentApiController::class, 'destroy'])
                ->whereNumber('payment')
                ->name('projects.payments.destroy');
            Route::post('/projects/{project}/payments/{payment}/approve', [PaymentApiController::class, 'approve'])
                ->whereNumber('payment')
                ->middleware('throttle:10,1')
                ->name('projects.payments.approve');
            Route::post('/projects/{project}/payments/{payment}/cancel', [PaymentApiController::class, 'cancel'])
                ->whereNumber('payment')
                ->middleware('throttle:10,1')
                ->name('projects.payments.cancel');

            Route::get('/projects/{project}/receipts', [PaymentApiController::class, 'indexReceipts'])
                ->name('projects.receipts.index');
            Route::get('/projects/{project}/receipts/{payment}', [PaymentApiController::class, 'show'])
                ->whereNumber('payment')
                ->name('projects.receipts.show');

            Route::get('/projects/{project}/vendor-bills/eligible-purchase-orders', [VendorBillApiController::class, 'eligiblePurchaseOrders'])
                ->name('projects.vendor-bills.eligible-purchase-orders');
            Route::get('/projects/{project}/vendor-bills/prefill/{purchaseOrder}', [VendorBillApiController::class, 'prefill'])
                ->whereNumber('purchaseOrder')
                ->name('projects.vendor-bills.prefill');
            Route::get('/projects/{project}/vendor-bills', [VendorBillApiController::class, 'index'])
                ->name('projects.vendor-bills.index');
            Route::post('/projects/{project}/vendor-bills', [VendorBillApiController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.vendor-bills.store');
            Route::get('/projects/{project}/vendor-bills/{vendorBill}', [VendorBillApiController::class, 'show'])
                ->whereNumber('vendorBill')
                ->name('projects.vendor-bills.show');
            Route::match(['put', 'patch'], '/projects/{project}/vendor-bills/{vendorBill}', [VendorBillApiController::class, 'update'])
                ->whereNumber('vendorBill')
                ->name('projects.vendor-bills.update');
            Route::delete('/projects/{project}/vendor-bills/{vendorBill}', [VendorBillApiController::class, 'destroy'])
                ->whereNumber('vendorBill')
                ->name('projects.vendor-bills.destroy');
            Route::post('/projects/{project}/vendor-bills/{vendorBill}/submit', [VendorBillApiController::class, 'submit'])
                ->whereNumber('vendorBill')
                ->middleware('throttle:10,1')
                ->name('projects.vendor-bills.submit');

            Route::get('/projects/{project}/expenses', [ExpenseApiController::class, 'index'])
                ->name('projects.expenses.index');
            Route::post('/projects/{project}/expenses', [ExpenseApiController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.expenses.store');
            Route::get('/projects/{project}/expenses/{expense}', [ExpenseApiController::class, 'show'])
                ->whereNumber('expense')
                ->name('projects.expenses.show');
            Route::match(['put', 'patch'], '/projects/{project}/expenses/{expense}', [ExpenseApiController::class, 'update'])
                ->whereNumber('expense')
                ->name('projects.expenses.update');
            Route::delete('/projects/{project}/expenses/{expense}', [ExpenseApiController::class, 'destroy'])
                ->whereNumber('expense')
                ->name('projects.expenses.destroy');
            Route::post('/projects/{project}/expenses/{expense}/submit', [ExpenseApiController::class, 'submit'])
                ->whereNumber('expense')
                ->middleware('throttle:10,1')
                ->name('projects.expenses.submit');
            Route::post('/projects/{project}/expenses/{expense}/mark-paid', [ExpenseApiController::class, 'markPaid'])
                ->whereNumber('expense')
                ->middleware('throttle:10,1')
                ->name('projects.expenses.mark-paid');
            Route::post('/projects/{project}/expenses/{expense}/reverse', [ExpenseApiController::class, 'reverse'])
                ->whereNumber('expense')
                ->middleware('throttle:10,1')
                ->name('projects.expenses.reverse');

            Route::get('/projects/{project}/retention-releases/eligible-balances', [RetentionReleaseApiController::class, 'eligibleBalances'])
                ->name('projects.retention-releases.eligible-balances');
            Route::get('/projects/{project}/retention-releases', [RetentionReleaseApiController::class, 'index'])
                ->name('projects.retention-releases.index');
            Route::post('/projects/{project}/retention-releases', [RetentionReleaseApiController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.retention-releases.store');
            Route::get('/projects/{project}/retention-releases/{retentionRelease}', [RetentionReleaseApiController::class, 'show'])
                ->whereNumber('retentionRelease')
                ->name('projects.retention-releases.show');
            Route::match(['put', 'patch'], '/projects/{project}/retention-releases/{retentionRelease}', [RetentionReleaseApiController::class, 'update'])
                ->whereNumber('retentionRelease')
                ->name('projects.retention-releases.update');
            Route::delete('/projects/{project}/retention-releases/{retentionRelease}', [RetentionReleaseApiController::class, 'destroy'])
                ->whereNumber('retentionRelease')
                ->name('projects.retention-releases.destroy');
            Route::post('/projects/{project}/retention-releases/{retentionRelease}/submit', [RetentionReleaseApiController::class, 'submit'])
                ->whereNumber('retentionRelease')
                ->middleware('throttle:10,1')
                ->name('projects.retention-releases.submit');

            // Approvals
            Route::get('/projects/{project}/approvals', [ApprovalController::class, 'index'])
                ->name('projects.approvals.index');
            Route::post('/projects/{project}/approvals/mr/{id}/approve', [ApprovalController::class, 'approve'])
                ->whereNumber('id')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.mr.approve');
            Route::post('/projects/{project}/approvals/mr/{id}/reject', [ApprovalController::class, 'reject'])
                ->whereNumber('id')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.mr.reject');
            Route::post('/projects/{project}/approvals/mr/{id}/send-back', [ApprovalController::class, 'sendBackMr'])
                ->whereNumber('id')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.mr.send-back');
            Route::post('/projects/{project}/approvals/po/{purchaseOrder}/approve', [ApprovalController::class, 'approvePo'])
                ->whereNumber('purchaseOrder')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.po.approve');
            Route::post('/projects/{project}/approvals/po/{purchaseOrder}/reject', [ApprovalController::class, 'rejectPo'])
                ->whereNumber('purchaseOrder')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.po.reject');
            Route::post('/projects/{project}/approvals/po/{purchaseOrder}/send-back', [ApprovalController::class, 'sendBackPo'])
                ->whereNumber('purchaseOrder')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.po.send-back');
            Route::post('/projects/{project}/approvals/grn/{grn}/approve', [ApprovalController::class, 'approveGrn'])
                ->whereNumber('grn')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.grn.approve');
            Route::post('/projects/{project}/approvals/grn/{grn}/reject', [ApprovalController::class, 'rejectGrn'])
                ->whereNumber('grn')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.grn.reject');
            Route::post('/projects/{project}/approvals/grn/{grn}/send-back', [ApprovalController::class, 'sendBackGrn'])
                ->whereNumber('grn')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.grn.send-back');
            Route::post('/projects/{project}/approvals/issue/{materialIssue}/approve', [ApprovalController::class, 'approveIssue'])
                ->whereNumber('materialIssue')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.issue.approve');
            Route::post('/projects/{project}/approvals/issue/{materialIssue}/reject', [ApprovalController::class, 'rejectIssue'])
                ->whereNumber('materialIssue')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.issue.reject');
            Route::post('/projects/{project}/approvals/return/{materialReturn}/approve', [ApprovalController::class, 'approveReturn'])
                ->whereNumber('materialReturn')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.return.approve');
            Route::post('/projects/{project}/approvals/return/{materialReturn}/reject', [ApprovalController::class, 'rejectReturn'])
                ->whereNumber('materialReturn')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.return.reject');
            Route::post('/projects/{project}/approvals/dpr/{dpr}/approve', [ApprovalController::class, 'approveDpr'])
                ->whereNumber('dpr')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.dpr.approve');
            Route::post('/projects/{project}/approvals/dpr/{dpr}/reject', [ApprovalController::class, 'rejectDpr'])
                ->whereNumber('dpr')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.dpr.reject');
            Route::post('/projects/{project}/approvals/dpr/{dpr}/send-back', [ApprovalController::class, 'sendBackDpr'])
                ->whereNumber('dpr')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.dpr.send-back');
            Route::post('/projects/{project}/approvals/wo/{workOrder}/approve', [ApprovalController::class, 'approveWo'])
                ->whereNumber('workOrder')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.wo.approve');
            Route::post('/projects/{project}/approvals/wo/{workOrder}/reject', [ApprovalController::class, 'rejectWo'])
                ->whereNumber('workOrder')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.wo.reject');
            Route::post('/projects/{project}/approvals/wo/{workOrder}/send-back', [ApprovalController::class, 'sendBackWo'])
                ->whereNumber('workOrder')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.wo.send-back');
            Route::post('/projects/{project}/approvals/bill/{subcontractorBill}/approve', [ApprovalController::class, 'approveBill'])
                ->whereNumber('subcontractorBill')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.bill.approve');
            Route::post('/projects/{project}/approvals/bill/{subcontractorBill}/reject', [ApprovalController::class, 'rejectBill'])
                ->whereNumber('subcontractorBill')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.bill.reject');
            Route::post('/projects/{project}/approvals/bill/{subcontractorBill}/send-back', [ApprovalController::class, 'sendBackBill'])
                ->whereNumber('subcontractorBill')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.bill.send-back');
            Route::post('/projects/{project}/approvals/client-invoice/{clientInvoice}/approve', [ApprovalController::class, 'approveClientInvoice'])
                ->whereNumber('clientInvoice')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.client-invoice.approve');
            Route::post('/projects/{project}/approvals/client-invoice/{clientInvoice}/reject', [ApprovalController::class, 'rejectClientInvoice'])
                ->whereNumber('clientInvoice')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.client-invoice.reject');
            Route::post('/projects/{project}/approvals/client-invoice/{clientInvoice}/send-back', [ApprovalController::class, 'sendBackClientInvoice'])
                ->whereNumber('clientInvoice')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.client-invoice.send-back');
            Route::post('/projects/{project}/approvals/vendor-bill/{vendorBill}/approve', [ApprovalController::class, 'approveVendorBill'])
                ->whereNumber('vendorBill')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.vendor-bill.approve');
            Route::post('/projects/{project}/approvals/vendor-bill/{vendorBill}/reject', [ApprovalController::class, 'rejectVendorBill'])
                ->whereNumber('vendorBill')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.vendor-bill.reject');
            Route::post('/projects/{project}/approvals/vendor-bill/{vendorBill}/send-back', [ApprovalController::class, 'sendBackVendorBill'])
                ->whereNumber('vendorBill')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.vendor-bill.send-back');
            Route::post('/projects/{project}/approvals/expense/{expense}/approve', [ApprovalController::class, 'approveExpense'])
                ->whereNumber('expense')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.expense.approve');
            Route::post('/projects/{project}/approvals/expense/{expense}/reject', [ApprovalController::class, 'rejectExpense'])
                ->whereNumber('expense')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.expense.reject');
            Route::post('/projects/{project}/approvals/expense/{expense}/send-back', [ApprovalController::class, 'sendBackExpense'])
                ->whereNumber('expense')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.expense.send-back');
            Route::post('/projects/{project}/approvals/retention-release/{retentionRelease}/approve', [ApprovalController::class, 'approveRetentionRelease'])
                ->whereNumber('retentionRelease')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.retention-release.approve');
            Route::post('/projects/{project}/approvals/retention-release/{retentionRelease}/reject', [ApprovalController::class, 'rejectRetentionRelease'])
                ->whereNumber('retentionRelease')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.retention-release.reject');
            Route::post('/projects/{project}/approvals/retention-release/{retentionRelease}/send-back', [ApprovalController::class, 'sendBackRetentionRelease'])
                ->whereNumber('retentionRelease')
                ->middleware('throttle:10,1')
                ->name('projects.approvals.retention-release.send-back');

            Route::get('/projects/{project}/site/summary', SiteSummaryController::class)
                ->name('projects.site.summary');

            Route::get('/projects/{project}/tasks', [TaskController::class, 'index'])
                ->name('projects.tasks.index');
            Route::get('/projects/{project}/tasks/{task}', [TaskController::class, 'show'])
                ->whereNumber('task')
                ->name('projects.tasks.show');
            Route::get('/projects/{project}/tasks/{task}/progress-entries', [ProgressController::class, 'forTask'])
                ->whereNumber('task')
                ->name('projects.tasks.progress-entries');

            Route::get('/projects/{project}/milestones', [MilestoneApiController::class, 'index'])
                ->name('projects.milestones.index');
            Route::get('/projects/{project}/milestones/{milestone}', [MilestoneApiController::class, 'show'])
                ->whereNumber('milestone')
                ->name('projects.milestones.show');
            Route::post('/projects/{project}/milestones/{milestone}/complete', [MilestoneApiController::class, 'complete'])
                ->whereNumber('milestone')
                ->middleware('throttle:10,1')
                ->name('projects.milestones.complete');

            Route::get('/projects/{project}/progress', [ProgressController::class, 'index'])
                ->name('projects.progress.index');
            Route::get('/projects/{project}/progress/summary', [ProgressController::class, 'project'])
                ->name('projects.progress.summary');

            Route::get('/projects/{project}/site-diaries', [SiteDiaryController::class, 'index'])
                ->name('projects.site-diaries.index');
            Route::post('/projects/{project}/site-diaries', [SiteDiaryController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('projects.site-diaries.store');
            Route::get('/projects/{project}/site-diaries/{siteDiary}', [SiteDiaryController::class, 'show'])
                ->whereNumber('siteDiary')
                ->name('projects.site-diaries.show');
            Route::match(['post', 'put', 'patch'], '/projects/{project}/site-diaries/{siteDiary}', [SiteDiaryController::class, 'update'])
                ->whereNumber('siteDiary')
                ->middleware('throttle:10,1')
                ->name('projects.site-diaries.update');
            Route::post('/projects/{project}/site-diaries/{siteDiary}/submit', [SiteDiaryController::class, 'submit'])
                ->whereNumber('siteDiary')
                ->middleware('throttle:10,1')
                ->name('projects.site-diaries.submit');
            Route::post('/projects/{project}/site-diaries/{siteDiary}/review', [SiteDiaryController::class, 'review'])
                ->whereNumber('siteDiary')
                ->middleware('throttle:10,1')
                ->name('projects.site-diaries.review');
            Route::post('/projects/{project}/site-diaries/{siteDiary}/approve', [SiteDiaryController::class, 'approve'])
                ->whereNumber('siteDiary')
                ->middleware('throttle:10,1')
                ->name('projects.site-diaries.approve');
            Route::post('/projects/{project}/site-diaries/{siteDiary}/reject', [SiteDiaryController::class, 'reject'])
                ->whereNumber('siteDiary')
                ->middleware('throttle:10,1')
                ->name('projects.site-diaries.reject');
            Route::post('/projects/{project}/site-diaries/{siteDiary}/photos', [SiteDiaryController::class, 'storePhoto'])
                ->whereNumber('siteDiary')
                ->middleware('throttle:30,1')
                ->name('projects.site-diaries.photos.store');
            Route::get('/projects/{project}/site-diaries/{siteDiary}/photos/{photo}', [SiteDiaryController::class, 'photo'])
                ->whereNumber(['siteDiary', 'photo'])
                ->name('projects.site-diaries.photos.show');
            Route::delete('/projects/{project}/site-diaries/{siteDiary}/photos/{photo}', [SiteDiaryController::class, 'destroyPhoto'])
                ->whereNumber(['siteDiary', 'photo'])
                ->middleware('throttle:30,1')
                ->name('projects.site-diaries.photos.destroy');

            Route::get('/projects/{project}/dprs/prefill', [DprController::class, 'prefill'])
                ->name('projects.dprs.prefill');
            Route::get('/projects/{project}/dprs', [DprController::class, 'index'])
                ->name('projects.dprs.index');
            Route::post('/projects/{project}/dprs', [DprController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('projects.dprs.store');
            Route::get('/projects/{project}/dprs/{dpr}', [DprController::class, 'show'])
                ->whereNumber('dpr')
                ->name('projects.dprs.show');
            Route::match(['put', 'patch'], '/projects/{project}/dprs/{dpr}', [DprController::class, 'update'])
                ->whereNumber('dpr')
                ->name('projects.dprs.update');
            Route::post('/projects/{project}/dprs/{dpr}/refresh', [DprController::class, 'refresh'])
                ->whereNumber('dpr')
                ->middleware('throttle:10,1')
                ->name('projects.dprs.refresh');
            Route::post('/projects/{project}/dprs/{dpr}/submit', [DprController::class, 'submit'])
                ->whereNumber('dpr')
                ->middleware('throttle:10,1')
                ->name('projects.dprs.submit');
            Route::post('/projects/{project}/dprs/{dpr}/reopen', [DprController::class, 'reopen'])
                ->whereNumber('dpr')
                ->middleware('throttle:10,1')
                ->name('projects.dprs.reopen');
            Route::get('/projects/{project}/dprs/{dpr}/pdf/view', [DprController::class, 'pdf'])
                ->whereNumber('dpr')
                ->name('projects.dprs.pdf.view');
            Route::get('/projects/{project}/dprs/{dpr}/pdf/download', [DprController::class, 'pdf'])
                ->whereNumber('dpr')
                ->name('projects.dprs.pdf.download');

            // GRNs
            Route::get('/projects/{project}/pos/pending', [GRNController::class, 'pendingPOs'])
                ->name('projects.pos.pending');
            Route::get('/projects/{project}/grns/eligible-purchase-orders', [GRNController::class, 'eligiblePurchaseOrders'])
                ->name('projects.grns.eligible-purchase-orders');
            Route::get('/projects/{project}/grns', [GRNController::class, 'index'])
                ->name('projects.grns.index');
            Route::post('/projects/{project}/grns', [GRNController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('projects.grns.store');
            Route::get('/projects/{project}/grns/{grn}', [GRNController::class, 'show'])
                ->whereNumber('grn')
                ->name('projects.grns.show');
            Route::match(['put', 'patch'], '/projects/{project}/grns/{grn}', [GRNController::class, 'update'])
                ->whereNumber('grn')
                ->name('projects.grns.update');
            Route::post('/projects/{project}/grns/{grn}/submit', [GRNController::class, 'submit'])
                ->whereNumber('grn')
                ->middleware('throttle:10,1')
                ->name('projects.grns.submit');

            Route::get('/projects/{project}/inventory/summary', [InventoryController::class, 'summary'])
                ->name('projects.inventory.summary');
            Route::get('/projects/{project}/inventory/stock', [InventoryController::class, 'stock'])
                ->name('projects.inventory.stock');
            Route::get('/projects/{project}/inventory/stock/detail', [InventoryController::class, 'stockShow'])
                ->name('projects.inventory.stock.show');
            Route::get('/projects/{project}/inventory/available', [InventoryController::class, 'available'])
                ->name('projects.inventory.available');
            Route::get('/projects/{project}/inventory/ledger', [InventoryController::class, 'ledger'])
                ->name('projects.inventory.ledger');
            Route::get('/projects/{project}/inventory/issues', [InventoryController::class, 'issues'])
                ->name('projects.inventory.issues');
            Route::get('/projects/{project}/inventory/transfers', [InventoryController::class, 'transfers'])
                ->name('projects.inventory.transfers');
            Route::get('/projects/{project}/inventory/returns', [InventoryController::class, 'returns'])
                ->name('projects.inventory.returns');
            Route::get('/projects/{project}/inventory/adjustments', [InventoryController::class, 'adjustments'])
                ->name('projects.inventory.adjustments');

            // Material issues
            Route::get('/projects/{project}/material-issues', [MaterialIssueController::class, 'index'])
                ->name('projects.material-issues.index');
            Route::post('/projects/{project}/material-issues', [MaterialIssueController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.material-issues.store');
            Route::get('/projects/{project}/material-issues/{materialIssue}', [MaterialIssueController::class, 'show'])
                ->whereNumber('materialIssue')
                ->name('projects.material-issues.show');
            Route::match(['put', 'patch'], '/projects/{project}/material-issues/{materialIssue}', [MaterialIssueController::class, 'update'])
                ->whereNumber('materialIssue')
                ->name('projects.material-issues.update');
            Route::post('/projects/{project}/material-issues/{materialIssue}/submit', [MaterialIssueController::class, 'submit'])
                ->whereNumber('materialIssue')
                ->middleware('throttle:10,1')
                ->name('projects.material-issues.submit');
            Route::post('/projects/{project}/material-issues/{materialIssue}/cancel', [MaterialIssueController::class, 'cancel'])
                ->whereNumber('materialIssue')
                ->middleware('throttle:10,1')
                ->name('projects.material-issues.cancel');

            // Stock transfers
            Route::get('/projects/{project}/stock-transfers', [StockTransferController::class, 'index'])
                ->name('projects.stock-transfers.index');
            Route::post('/projects/{project}/stock-transfers', [StockTransferController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.stock-transfers.store');
            Route::get('/projects/{project}/stock-transfers/{stockTransfer}', [StockTransferController::class, 'show'])
                ->whereNumber('stockTransfer')
                ->name('projects.stock-transfers.show');
            Route::match(['put', 'patch'], '/projects/{project}/stock-transfers/{stockTransfer}', [StockTransferController::class, 'update'])
                ->whereNumber('stockTransfer')
                ->name('projects.stock-transfers.update');
            Route::post('/projects/{project}/stock-transfers/{stockTransfer}/dispatch', [StockTransferController::class, 'dispatchTransfer'])
                ->whereNumber('stockTransfer')
                ->middleware('throttle:10,1')
                ->name('projects.stock-transfers.dispatch');
            Route::post('/projects/{project}/stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive'])
                ->whereNumber('stockTransfer')
                ->middleware('throttle:10,1')
                ->name('projects.stock-transfers.receive');
            Route::post('/projects/{project}/stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])
                ->whereNumber('stockTransfer')
                ->middleware('throttle:10,1')
                ->name('projects.stock-transfers.cancel');
            Route::post('/projects/{project}/stock-transfers/{stockTransfer}/close-short', [StockTransferController::class, 'closeShort'])
                ->whereNumber('stockTransfer')
                ->middleware('throttle:10,1')
                ->name('projects.stock-transfers.close-short');

            // Material returns
            Route::get('/projects/{project}/material-returns', [MaterialReturnController::class, 'index'])
                ->name('projects.material-returns.index');
            Route::post('/projects/{project}/material-returns', [MaterialReturnController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.material-returns.store');
            Route::get('/projects/{project}/material-returns/{materialReturn}', [MaterialReturnController::class, 'show'])
                ->whereNumber('materialReturn')
                ->name('projects.material-returns.show');
            Route::match(['put', 'patch'], '/projects/{project}/material-returns/{materialReturn}', [MaterialReturnController::class, 'update'])
                ->whereNumber('materialReturn')
                ->name('projects.material-returns.update');
            Route::post('/projects/{project}/material-returns/{materialReturn}/submit', [MaterialReturnController::class, 'submit'])
                ->whereNumber('materialReturn')
                ->middleware('throttle:10,1')
                ->name('projects.material-returns.submit');
            Route::post('/projects/{project}/material-returns/{materialReturn}/cancel', [MaterialReturnController::class, 'cancel'])
                ->whereNumber('materialReturn')
                ->middleware('throttle:10,1')
                ->name('projects.material-returns.cancel');

            // Stock adjustments (also covers physical count / opening via reason)
            Route::get('/projects/{project}/stock-adjustments', [StockAdjustmentController::class, 'index'])
                ->name('projects.stock-adjustments.index');
            Route::post('/projects/{project}/stock-adjustments', [StockAdjustmentController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.stock-adjustments.store');
            Route::get('/projects/{project}/stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'show'])
                ->whereNumber('stockAdjustment')
                ->name('projects.stock-adjustments.show');
            Route::match(['put', 'patch'], '/projects/{project}/stock-adjustments/{stockAdjustment}', [StockAdjustmentController::class, 'update'])
                ->whereNumber('stockAdjustment')
                ->name('projects.stock-adjustments.update');
            Route::post('/projects/{project}/stock-adjustments/{stockAdjustment}/submit', [StockAdjustmentController::class, 'submit'])
                ->whereNumber('stockAdjustment')
                ->middleware('throttle:10,1')
                ->name('projects.stock-adjustments.submit');
            Route::post('/projects/{project}/stock-adjustments/{stockAdjustment}/approve', [StockAdjustmentController::class, 'approve'])
                ->whereNumber('stockAdjustment')
                ->middleware('throttle:10,1')
                ->name('projects.stock-adjustments.approve');
            Route::post('/projects/{project}/stock-adjustments/{stockAdjustment}/reject', [StockAdjustmentController::class, 'reject'])
                ->whereNumber('stockAdjustment')
                ->middleware('throttle:10,1')
                ->name('projects.stock-adjustments.reject');
            Route::post('/projects/{project}/stock-adjustments/{stockAdjustment}/cancel', [StockAdjustmentController::class, 'cancel'])
                ->whereNumber('stockAdjustment')
                ->middleware('throttle:10,1')
                ->name('projects.stock-adjustments.cancel');

            Route::get('/projects/{project}/quality/summary', QualitySummaryApiController::class)
                ->name('projects.quality.summary');

            Route::get('/projects/{project}/quality-inspections', [QualityInspectionApiController::class, 'index'])
                ->name('projects.quality-inspections.index');
            Route::post('/projects/{project}/quality-inspections', [QualityInspectionApiController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.quality-inspections.store');

            Route::get('/projects/{project}/ncrs', [NcrApiController::class, 'index'])
                ->name('projects.ncrs.index');
            Route::post('/projects/{project}/ncrs', [NcrApiController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.ncrs.store');

            Route::get('/projects/{project}/documents/folders', [DocumentFolderApiController::class, 'index'])
                ->name('projects.documents.folders.index');
            Route::post('/projects/{project}/documents/folders', [DocumentFolderApiController::class, 'store'])
                ->middleware('throttle:20,1')
                ->name('projects.documents.folders.store');

            Route::get('/projects/{project}/documents', [DocumentApiController::class, 'index'])
                ->name('projects.documents.index');
            Route::post('/projects/{project}/documents', [DocumentApiController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('projects.documents.store');

            Route::scopeBindings()->group(function () {
                Route::prefix('/projects/{project}/quality-inspections/{qualityInspection}')
                    ->whereNumber('qualityInspection')
                    ->name('projects.quality-inspections.')
                    ->group(function () {
                        Route::get('/', [QualityInspectionApiController::class, 'show'])->name('show');
                        Route::match(['put', 'patch'], '/', [QualityInspectionApiController::class, 'update'])->name('update');
                        Route::delete('/', [QualityInspectionApiController::class, 'destroy'])->name('destroy');
                        Route::post('/schedule', [QualityInspectionApiController::class, 'schedule'])
                            ->middleware('throttle:10,1')
                            ->name('schedule');
                        Route::post('/record', [QualityInspectionApiController::class, 'record'])
                            ->middleware('throttle:20,1')
                            ->name('record');
                        Route::post('/complete', [QualityInspectionApiController::class, 'complete'])
                            ->middleware('throttle:10,1')
                            ->name('complete');
                    });

                Route::prefix('/projects/{project}/ncrs/{ncr}')
                    ->whereNumber('ncr')
                    ->name('projects.ncrs.')
                    ->group(function () {
                        Route::get('/', [NcrApiController::class, 'show'])->name('show');
                        Route::match(['put', 'patch'], '/', [NcrApiController::class, 'update'])->name('update');
                        Route::delete('/', [NcrApiController::class, 'destroy'])->name('destroy');
                        Route::post('/start', [NcrApiController::class, 'start'])
                            ->middleware('throttle:10,1')
                            ->name('start');
                        Route::post('/resolve', [NcrApiController::class, 'resolve'])
                            ->middleware('throttle:10,1')
                            ->name('resolve');
                        Route::post('/verify', [NcrApiController::class, 'verify'])
                            ->middleware('throttle:10,1')
                            ->name('verify');
                        Route::post('/reopen', [NcrApiController::class, 'reopen'])
                            ->middleware('throttle:10,1')
                            ->name('reopen');
                        Route::post('/close', [NcrApiController::class, 'close'])
                            ->middleware('throttle:10,1')
                            ->name('close');
                    });

                Route::prefix('/projects/{project}/documents/folders/{documentFolder}')
                    ->whereNumber('documentFolder')
                    ->name('projects.documents.folders.')
                    ->group(function () {
                        Route::match(['put', 'patch'], '/', [DocumentFolderApiController::class, 'update'])->name('update');
                        Route::delete('/', [DocumentFolderApiController::class, 'destroy'])->name('destroy');
                    });

                Route::prefix('/projects/{project}/documents/{document}')
                    ->whereNumber('document')
                    ->name('projects.documents.')
                    ->group(function () {
                        Route::get('/', [DocumentApiController::class, 'show'])->name('show');
                        Route::match(['put', 'patch'], '/', [DocumentApiController::class, 'update'])->name('update');
                        Route::delete('/', [DocumentApiController::class, 'destroy'])->name('destroy');
                        Route::post('/versions', [DocumentApiController::class, 'storeVersion'])
                            ->middleware('throttle:30,1')
                            ->name('versions.store');
                        Route::post('/publish', [DocumentApiController::class, 'publish'])
                            ->middleware('throttle:10,1')
                            ->name('publish');
                        Route::post('/archive', [DocumentApiController::class, 'archive'])
                            ->middleware('throttle:10,1')
                            ->name('archive');
                        Route::post('/restore', [DocumentApiController::class, 'restore'])
                            ->middleware('throttle:10,1')
                            ->name('restore');
                    });

                Route::get('/projects/{project}/drawings', [DrawingController::class, 'index'])
                    ->name('projects.drawings.index');
                Route::post('/projects/{project}/drawings', [DrawingController::class, 'store'])
                    ->middleware('throttle:30,1')
                    ->name('projects.drawings.store');
                Route::prefix('/projects/{project}/drawings/{drawing}')->whereNumber('drawing')->name('projects.drawings.')->group(function () {
                    Route::get('/', [DrawingController::class, 'show'])->name('show');
                    Route::post('/revisions', [DrawingController::class, 'storeRevision'])
                        ->middleware('throttle:30,1')
                        ->name('revisions.store');
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

                Route::get('/projects/{project}/boqs/eligible-drawings', [BoqController::class, 'eligibleDrawings'])
                    ->name('projects.boqs.eligible-drawings');
                Route::get('/projects/{project}/boqs', [BoqController::class, 'index'])
                    ->name('projects.boqs.index');
                Route::post('/projects/{project}/boqs', [BoqController::class, 'store'])
                    ->name('projects.boqs.store');
                Route::get('/projects/{project}/boqs/{boq}', [BoqController::class, 'show'])
                    ->whereNumber('boq')
                    ->name('projects.boqs.show');
                Route::post('/projects/{project}/boqs/{boq}/submit', [BoqController::class, 'submit'])
                    ->whereNumber('boq')
                    ->name('projects.boqs.submit');
            });
        });
    });
});
