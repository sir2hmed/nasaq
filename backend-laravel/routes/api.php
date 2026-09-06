<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AgentOutputController;
use App\Http\Controllers\AiProviderConfigurationController;
use App\Http\Controllers\AiProviderRoutingController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\IntegrationConnectionController;
use App\Http\Controllers\InternalExecutionEventController;
use App\Http\Controllers\InternalIntegrationCredentialController;
use App\Http\Controllers\InternalProviderResolutionController;
use App\Http\Controllers\PlatformIntegrationController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\WorkflowRunController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::post('/internal/executions/{run}/events', InternalExecutionEventController::class)
    ->middleware(['service.token', 'throttle:600,1'])
    ->name('internal.executions.events');
Route::get('/internal/users/{user}/integrations/{provider}', InternalIntegrationCredentialController::class)
    ->middleware(['service.token', 'throttle:120,1'])
    ->name('internal.integrations.credentials');
Route::get('/internal/runs/{run}/provider-resolution', InternalProviderResolutionController::class)
    ->middleware(['service.token', 'throttle:120,1'])
    ->name('internal.runs.provider-resolution');

Route::prefix('auth')->name('auth.')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->name('register');
        Route::post('/login', [AuthController::class, 'login'])->name('login');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::patch('/locale', [AuthController::class, 'updateLocale'])->name('locale');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/integrations', [IntegrationConnectionController::class, 'index'])
        ->name('integrations.index');
    Route::get('/integrations/{provider}/status', [IntegrationConnectionController::class, 'status'])
        ->name('integrations.status');
    Route::post('/integrations/{provider}/connect', [IntegrationConnectionController::class, 'connect'])
        ->middleware('throttle:20,1')
        ->name('integrations.connect');
    Route::delete('/integrations/{provider}', [IntegrationConnectionController::class, 'disconnect'])
        ->middleware('throttle:20,1')
        ->name('integrations.disconnect');
    Route::post('/workflows/{workflow}/run', [WorkflowRunController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('workflows.run');
    Route::post('/workflows/{workflow}/validate', [WorkflowController::class, 'validateGraph'])
        ->name('workflows.validate');
    Route::post('/workflows/{workflow}/duplicate', [WorkflowController::class, 'duplicate'])
        ->name('workflows.duplicate');
    Route::apiResource('workflows', WorkflowController::class);
    Route::get('/runs', [WorkflowRunController::class, 'index'])->name('runs.index');
    Route::get('/runs/{run}', [WorkflowRunController::class, 'show'])->name('runs.show');
    Route::post('/runs/{run}/cancel', [WorkflowRunController::class, 'cancel'])
        ->middleware('throttle:30,1')
        ->name('runs.cancel');
    Route::post('/runs/{run}/approval/{nodeKey}/approve', [WorkflowRunController::class, 'approve'])
        ->middleware('throttle:30,1')
        ->name('runs.approval.approve');
    Route::post('/runs/{run}/approval/{nodeKey}/reject', [WorkflowRunController::class, 'reject'])
        ->middleware('throttle:30,1')
        ->name('runs.approval.reject');
    Route::get('/runs/{run}/logs', [WorkflowRunController::class, 'logs'])->name('runs.logs');
    Route::get('/runs/{run}/outputs', [WorkflowRunController::class, 'outputs'])->name('runs.outputs');
    Route::get('/outputs/{output}/download', [AgentOutputController::class, 'download'])
        ->middleware('throttle:120,1')
        ->name('outputs.download');
    Route::get('/outputs/{output}/stream', [AgentOutputController::class, 'stream'])
        ->middleware('throttle:120,1')
        ->name('outputs.stream');
});

Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'active', 'admin', 'throttle:60,1'])->group(function () {
    Route::get('/overview', [AdminController::class, 'overview'])->name('overview');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::patch('/users/{user}/role', [AdminController::class, 'updateRole'])->name('users.role');
    Route::patch('/users/{user}/status', [AdminController::class, 'updateStatus'])->name('users.status');
    Route::get('/providers', [AdminController::class, 'providers'])->name('providers');
    Route::get('/system', [AdminController::class, 'system'])->name('system');
    Route::get('/runs', [AdminController::class, 'runs'])->name('runs');
    Route::get('/logs', [AdminController::class, 'logs'])->name('logs');

    Route::get('/integrations', [PlatformIntegrationController::class, 'index'])->name('integrations.index');
    Route::get('/integrations/{provider}', [PlatformIntegrationController::class, 'show'])->name('integrations.show');
    Route::put('/integrations/{provider}', [PlatformIntegrationController::class, 'update'])->name('integrations.update');
    Route::post('/integrations/{provider}/test', [PlatformIntegrationController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('integrations.test');
    Route::post('/integrations/{provider}/enable', [PlatformIntegrationController::class, 'enable'])->name('integrations.enable');
    Route::post('/integrations/{provider}/disable', [PlatformIntegrationController::class, 'disable'])->name('integrations.disable');
    Route::delete('/integrations/{provider}/credentials', [PlatformIntegrationController::class, 'destroyCredentials'])->name('integrations.destroy');

    Route::get('/provider-config', [AiProviderConfigurationController::class, 'index'])->name('provider-config.index');
    Route::put('/provider-config', [AiProviderConfigurationController::class, 'update'])->name('provider-config.update');
    Route::post('/provider-config/{provider}/test', [AiProviderConfigurationController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('provider-config.test');
    Route::get('/provider-routing', [AiProviderRoutingController::class, 'index'])->name('provider-routing.index');
    Route::post('/provider-routing/configurations', [AiProviderRoutingController::class, 'store'])->name('provider-routing.store');
    Route::post('/provider-routing/configurations/{configuration}/test', [AiProviderRoutingController::class, 'test'])->middleware('throttle:10,1')->name('provider-routing.test');
    Route::post('/provider-routing/configurations/{configuration}/activate', [AiProviderRoutingController::class, 'activate'])->name('provider-routing.activate');
    Route::delete('/provider-routing/configurations/{configuration}', [AiProviderRoutingController::class, 'destroy'])->name('provider-routing.destroy');
    Route::put('/provider-routing/categories/{category}/fallbacks', [AiProviderRoutingController::class, 'reorder'])->name('provider-routing.reorder');
});
