<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\Site\ApprovalController;
use App\Http\Controllers\Api\V1\Site\AttendanceController;
use App\Http\Controllers\Api\V1\Site\GRNController;
use App\Http\Controllers\Api\V1\Site\InventoryController;
use App\Http\Controllers\Api\V1\Site\LabourController;
use App\Http\Controllers\Api\V1\Site\MaterialRequisitionController;
use App\Http\Controllers\Api\V1\Site\SiteDiaryController;
use App\Http\Controllers\Core\DeviceTokenController;
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

        Route::middleware('project.access')->whereNumber('project')->group(function () {
            Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::get('/projects/{project}/attendance', [AttendanceController::class, 'index'])
                ->name('projects.attendance.index');
            Route::get('/projects/{project}/labour', [LabourController::class, 'index'])
                ->name('projects.labour.index');
            Route::get('/projects/{project}/labour/roster', [LabourController::class, 'roster'])
                ->name('projects.labour.roster');
            Route::get('/projects/{project}/labour/attendance', [LabourController::class, 'attendance'])
                ->name('projects.labour.attendance');
            Route::post('/projects/{project}/labour/attendance', [LabourController::class, 'storeAttendance'])
                ->middleware('throttle:10,1')
                ->name('projects.labour.attendance.store');
            Route::get('/projects/{project}/labour/payments', [LabourController::class, 'payments'])
                ->name('projects.labour.payments');
            Route::get('/projects/{project}/material-requisitions', [MaterialRequisitionController::class, 'index'])
                ->name('projects.material-requisitions.index');
            Route::get('/projects/{project}/material-requisitions/{material_requisition}', [MaterialRequisitionController::class, 'show'])
                ->whereNumber('material_requisition')
                ->name('projects.material-requisitions.show');
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
            Route::get('/projects/{project}/site-diaries', [SiteDiaryController::class, 'index'])
                ->name('projects.site-diaries.index');
            Route::post('/projects/{project}/site-diaries', [SiteDiaryController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('projects.site-diaries.store');
            Route::get('/projects/{project}/site-diaries/{siteDiary}', [SiteDiaryController::class, 'show'])
                ->whereNumber('siteDiary')
                ->name('projects.site-diaries.show');
            Route::match(['post', 'put'], '/projects/{project}/site-diaries/{siteDiary}', [SiteDiaryController::class, 'update'])
                ->whereNumber('siteDiary')
                ->middleware('throttle:10,1')
                ->name('projects.site-diaries.update');
            Route::get('/projects/{project}/site-diaries/{siteDiary}/photos/{photo}', [SiteDiaryController::class, 'photo'])
                ->whereNumber(['siteDiary', 'photo'])
                ->name('projects.site-diaries.photos.show');
            Route::get('/projects/{project}/pos/pending', [GRNController::class, 'pendingPOs'])
                ->name('projects.pos.pending');
            Route::post('/projects/{project}/grns', [GRNController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('projects.grns.store');
            Route::get('/projects/{project}/inventory/stock', [InventoryController::class, 'stock'])
                ->name('projects.inventory.stock');
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
        });
    });
});
