<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\AdminAuditLog;
use App\Models\AiProviderConfiguration;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminController extends Controller
{
    public function overview(): JsonResponse
    {
        $totalUsers = User::count();
        $adminUsers = User::where('role', 'admin')->count();
        $activeUsers = User::where('account_status', 'active')->count();
        $suspendedUsers = User::where('account_status', 'suspended')->count();

        $totalWorkflows = Workflow::count();

        $totalRuns = WorkflowRun::count();
        $runsToday = WorkflowRun::where('created_at', '>=', now()->startOfDay())->count();
        $successfulRuns = WorkflowRun::where('status', 'success')->count();
        $failedRuns = WorkflowRun::where('status', 'failed')->count();
        $completedRuns = $successfulRuns + $failedRuns;
        $successRate = $completedRuns > 0 ? round(($successfulRuns / $completedRuns) * 100, 1) : 0;

        $runStats = [
            'queued' => WorkflowRun::where('status', 'queued')->count(),
            'running' => WorkflowRun::where('status', 'running')->count(),
            'waiting_for_approval' => WorkflowRun::where('status', 'waiting_for_approval')->count(),
            'success' => $successfulRuns,
            'failed' => $failedRuns,
            'cancelled' => WorkflowRun::where('status', 'cancelled')->count(),
        ];

        return response()->json([
            'data' => [
                'overview' => [
                    'users' => [
                        'total' => $totalUsers,
                        'admins' => $adminUsers,
                        'active' => $activeUsers,
                        'suspended' => $suspendedUsers,
                    ],
                    'workflows' => [
                        'total' => $totalWorkflows,
                    ],
                    'runs' => array_merge([
                        'total' => $totalRuns,
                        'runs_today' => $runsToday,
                        'success_rate' => $successRate,
                    ], $runStats),
                ],
            ],
            'message' => 'Admin overview statistics retrieved.',
            'errors' => null,
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $users = User::query()
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'data' => [
                'users' => $users->map(fn (User $user) => UserResource::make($user)->resolve($request)),
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ],
            ],
            'message' => 'User list retrieved.',
            'errors' => null,
        ]);
    }

    public function updateRole(Request $request, User $user, AdminAuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['user', 'admin'])],
            'confirm_password' => ['required', 'string'],
        ]);

        if (! Hash::check($validated['confirm_password'], $request->user()->password)) {
            return response()->json(['data' => null, 'message' => 'Invalid administrator password confirmation.', 'errors' => ['confirm_password' => ['Invalid administrator password confirmation.']]], 422);
        }

        try {
            $updatedUser = DB::transaction(function () use ($user, $validated, $request, $audit): User {
                $activeAdmins = User::query()->where('role', 'admin')->where('account_status', 'active')->orderBy('id')->lockForUpdate()->get();
                $target = User::query()->lockForUpdate()->findOrFail($user->id);

                if ($target->isAdmin() && $target->isActive() && $validated['role'] === 'user' && $activeAdmins->count() <= 1) {
                    abort(422, 'Cannot demote the last active administrator.');
                }

                $previousRole = $target->role;
                $target->update(['role' => $validated['role']]);
                $audit->log($request, 'user.role_updated', 'user', (string) $target->id, ['from' => $previousRole, 'to' => $target->role]);

                return $target->refresh();
            });
        } catch (HttpException $exception) {
            return response()->json(['data' => null, 'message' => $exception->getMessage(), 'errors' => ['role' => [$exception->getMessage()]]], $exception->getStatusCode());
        }

        return response()->json([
            'data' => ['user' => UserResource::make($updatedUser)->resolve($request)],
            'message' => 'User role updated successfully.',
            'errors' => null,
        ]);
    }

    public function updateStatus(Request $request, User $user, AdminAuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'account_status' => ['required', Rule::in(['active', 'suspended'])],
            'confirm_password' => ['required', 'string'],
        ]);

        if (! Hash::check($validated['confirm_password'], $request->user()->password)) {
            return response()->json(['data' => null, 'message' => 'Invalid administrator password confirmation.', 'errors' => ['confirm_password' => ['Invalid administrator password confirmation.']]], 422);
        }

        try {
            $updatedUser = DB::transaction(function () use ($user, $validated, $request, $audit): User {
                $activeAdmins = User::query()->where('role', 'admin')->where('account_status', 'active')->orderBy('id')->lockForUpdate()->get();
                $target = User::query()->lockForUpdate()->findOrFail($user->id);

                if ($target->isAdmin() && $target->isActive() && $validated['account_status'] === 'suspended' && $activeAdmins->count() <= 1) {
                    abort(422, 'Cannot suspend the last active administrator.');
                }

                $previousStatus = $target->account_status;
                $target->update(['account_status' => $validated['account_status']]);
                $audit->log($request, 'user.status_updated', 'user', (string) $target->id, ['from' => $previousStatus, 'to' => $target->account_status]);

                return $target->refresh();
            });
        } catch (HttpException $exception) {
            return response()->json(['data' => null, 'message' => $exception->getMessage(), 'errors' => ['account_status' => [$exception->getMessage()]]], $exception->getStatusCode());
        }

        return response()->json([
            'data' => ['user' => UserResource::make($updatedUser)->resolve($request)],
            'message' => 'User account status updated successfully.',
            'errors' => null,
        ]);
    }

    public function providers(): JsonResponse
    {
        $llmConfigurations = AiProviderConfiguration::with('platformIntegration')->get()->map(fn ($config) => [
            'provider' => $config->provider,
            'configured' => $config->platformIntegration !== null && ! empty($config->platformIntegration->encrypted_credentials),
            'enabled' => (bool) $config->is_enabled && (bool) ($config->platformIntegration?->is_enabled),
            'default' => (bool) $config->is_default,
            'model' => $config->default_model,
            'status' => $config->connection_status,
        ])->values();
        $driveConnections = IntegrationConnection::where('provider', 'google_drive')->count();
        $youtubeConnections = IntegrationConnection::where('provider', 'youtube')->count();
        $smtpConnections = IntegrationConnection::where('provider', 'smtp')->count();

        return response()->json([
            'data' => [
                'providers' => [
                    'llm' => $llmConfigurations,
                    'video' => [
                        'name' => 'FFmpeg Local Synthesis',
                        'status' => 'active',
                        'demo_available' => true,
                    ],
                    'integrations' => [
                        'google_drive' => ['connected_count' => $driveConnections],
                        'youtube' => ['connected_count' => $youtubeConnections],
                        'smtp' => ['connected_count' => $smtpConnections],
                    ],
                ],
            ],
            'message' => 'AI providers status retrieved.',
            'errors' => null,
        ]);
    }

    public function system(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            $database = 'reachable';
        } catch (\Throwable) {
            $database = 'unreachable';
        }
        try {
            $response = Http::acceptJson()->timeout(3)->get(rtrim((string) config('services.ai_orchestrator.url'), '/').'/health');
            $health = $response->json();
            $fastapi = $response->successful() ? 'reachable' : 'unreachable';
            $redis = data_get($health, 'checks.redis.ready') ? 'reachable' : 'unreachable';
            $celery = data_get($health, 'checks.celery.ready') ? 'reachable' : 'unreachable';
        } catch (\Throwable) {
            $fastapi = $redis = $celery = 'unreachable';
        }

        return response()->json(['data' => ['system' => [
            'laravel' => $database === 'reachable' ? 'reachable' : 'degraded', 'database' => $database, 'fastapi' => $fastapi,
            'redis' => $redis, 'celery' => $celery,
        ]], 'message' => 'Measured system health retrieved.', 'errors' => null]);
    }

    public function runs(): JsonResponse
    {
        $runs = WorkflowRun::with(['workflow', 'user'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'data' => [
                'runs' => $runs->map(fn (WorkflowRun $run) => [
                    'id' => $run->id,
                    'workflow_id' => $run->workflow_id,
                    'workflow_name' => $run->workflow?->name ?? 'Deleted Workflow',
                    'user_id' => $run->user_id,
                    'user_email' => $run->user?->email ?? 'Deleted User',
                    'status' => $run->status,
                    'mode' => $run->mode,
                    'created_at' => $run->created_at?->toISOString(),
                ]),
                'pagination' => [
                    'current_page' => $runs->currentPage(),
                    'last_page' => $runs->lastPage(),
                    'per_page' => $runs->perPage(),
                    'total' => $runs->total(),
                ],
            ],
            'message' => 'System workflow runs retrieved.',
            'errors' => null,
        ]);
    }

    public function logs(): JsonResponse
    {
        $logs = AdminAuditLog::query()
            ->with('adminUser:id,name,email')
            ->orderByDesc('created_at')
            ->paginate(30);

        return response()->json([
            'data' => [
                'logs' => collect($logs->items())->map(fn (AdminAuditLog $log) => [
                    'id' => $log->id,
                    'occurred_at' => $log->created_at?->toISOString(),
                    'event_type' => $log->action,
                    'node_key' => $log->target_type ? "{$log->target_type}:{$log->target_id}" : null,
                    'message' => $log->adminUser?->email ?? 'Deleted administrator',
                    'metadata' => $log->metadata,
                ])->values(),
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
            ],
            'message' => 'System audit logs retrieved.',
            'errors' => null,
        ]);
    }
}
