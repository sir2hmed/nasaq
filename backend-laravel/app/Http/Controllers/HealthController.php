<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => ['ready' => true, 'detail' => 'dependency check disabled'],
            'redis' => ['ready' => true, 'detail' => 'dependency check disabled'],
        ];

        if (config('nasaq.health.check_dependencies')) {
            $checks['database'] = $this->probeDatabase();
            $checks['redis'] = $this->probeRedis();
        }

        $ready = collect($checks)->every(fn (array $check): bool => $check['ready']);

        return response()->json([
            'data' => [
                'status' => $ready ? 'ok' : 'degraded',
                'service' => 'laravel',
                'version' => config('nasaq.version'),
                'environment' => app()->environment(),
                'mode' => config('nasaq.demo_mode') ? 'demo' : 'real',
                'checks' => $checks,
            ],
            'message' => $ready ? 'Nasaq API is ready.' : 'One or more dependencies are unavailable.',
            'errors' => null,
        ], $ready ? 200 : 503);
    }

    /** @return array{ready: bool, detail: string} */
    private function probeDatabase(): array
    {
        try {
            DB::select('select 1');

            return ['ready' => true, 'detail' => 'ready'];
        } catch (Throwable $exception) {
            report($exception);

            return ['ready' => false, 'detail' => $exception::class];
        }
    }

    /** @return array{ready: bool, detail: string} */
    private function probeRedis(): array
    {
        try {
            Redis::connection()->command('ping');

            return ['ready' => true, 'detail' => 'ready'];
        } catch (Throwable $exception) {
            report($exception);

            return ['ready' => false, 'detail' => $exception::class];
        }
    }
}
