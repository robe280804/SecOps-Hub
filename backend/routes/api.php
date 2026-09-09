<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProjectCollaboratorLookupController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectMembershipController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/login', [AuthController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'show'])->name('me.show');
        Route::delete('/logout', [AuthController::class, 'destroy'])->name('logout');
        Route::apiResource('users', UserController::class);
        Route::apiResource('projects', ProjectController::class);
        Route::get('projects/{project}/collaborator-lookup', ProjectCollaboratorLookupController::class)
            ->middleware('throttle:collaborator-lookup')->name('projects.collaborator-lookup');
        Route::apiResource('projects.memberships', ProjectMembershipController::class)
            ->only(['index', 'store', 'update', 'destroy'])->scoped();
    });
});
