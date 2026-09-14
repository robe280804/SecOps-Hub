<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EnvironmentTerminalController;
use App\Http\Controllers\Api\V1\ProjectCollaboratorLookupController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectEnvironmentController;
use App\Http\Controllers\Api\V1\ProjectEnvironmentOptionsController;
use App\Http\Controllers\Api\V1\ProjectMembershipController;
use App\Http\Controllers\Api\V1\StartProjectEnvironmentController;
use App\Http\Controllers\Api\V1\StopProjectEnvironmentController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/login', [AuthController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'show'])->name('me.show');
        Route::get('/terminal/authorize', [EnvironmentTerminalController::class, 'show'])
            ->withoutMiddleware('throttle:api')->middleware('throttle:terminal-check')->name('terminal.authorize');
        Route::post('projects/{project}/environments/{environment}/terminal', [EnvironmentTerminalController::class, 'store'])
            ->scopeBindings()->middleware('throttle:10,1')->name('projects.environments.terminal');
        Route::post('projects/{project}/environments/{environment}/stop', StopProjectEnvironmentController::class)
            ->scopeBindings()->name('projects.environments.stop');
        Route::delete('/logout', [AuthController::class, 'destroy'])->name('logout');
        Route::apiResource('users', UserController::class);
        Route::apiResource('projects', ProjectController::class);
        Route::apiResource('projects.environments', ProjectEnvironmentController::class)->scoped();
        Route::post('projects/{project}/environments/{environment}/start', StartProjectEnvironmentController::class)
            ->scopeBindings()->name('projects.environments.start');
        Route::get('projects/{project}/environment-options', ProjectEnvironmentOptionsController::class)
            ->name('projects.environment-options');
        Route::get('projects/{project}/collaborator-lookup', ProjectCollaboratorLookupController::class)
            ->middleware('throttle:collaborator-lookup')->name('projects.collaborator-lookup');
        Route::apiResource('projects.memberships', ProjectMembershipController::class)
            ->only(['index', 'store', 'update', 'destroy'])->scoped();
    });
});
