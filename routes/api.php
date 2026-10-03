<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProjectController;
use Illuminate\Support\Facades\Route;

// Mobile / integration API. Token auth (Sanctum); company chosen with the X-Company-Id header.
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login')->name('auth.login');

    Route::middleware(['auth:sanctum', 'company', 'throttle:120,1'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');

        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])
            ->whereNumber('project')->middleware('project.access')->name('projects.show');
    });
});
