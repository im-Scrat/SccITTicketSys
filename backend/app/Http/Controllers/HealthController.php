<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Infrastructure smoke-test (not a business feature). Confirms the app can
 * reach PostgreSQL and Redis.
 *
 * Extracted verbatim from the former `/api/health` route closure so the HTTP
 * route table contains no closures and can be serialized by `route:cache`
 * (a production boot-time optimization). Behaviour and response are unchanged.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = ['app' => 'ok'];

        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (Throwable $e) {
            $checks['database'] = 'error: '.$e->getMessage();
        }

        try {
            Redis::connection()->ping();
            $checks['redis'] = 'ok';
        } catch (Throwable $e) {
            $checks['redis'] = 'error: '.$e->getMessage();
        }

        $ok = ! collect($checks)->contains(fn ($v) => str_starts_with((string) $v, 'error'));

        return response()->json([
            'status' => $ok ? 'healthy' : 'degraded',
            'checks' => $checks,
            'laravel' => app()->version(),
        ], $ok ? 200 : 503);
    }
}
