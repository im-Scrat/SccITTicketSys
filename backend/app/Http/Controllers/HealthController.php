<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Infrastructure smoke-test (not a business feature). Confirms the app can
 * reach PostgreSQL and, where the deployment uses it, Redis.
 *
 * The route is public, so the response says only *whether* a dependency is
 * reachable — never why not. It used to return the raw exception message and
 * the framework version: a failed database connection message names the host
 * and the database user, and a version string tells a scanner which advisories
 * to try. The reason is written to the log instead, where the secret-redacting
 * processor runs (App\Support\Logging\RedactSecretsProcessor).
 *
 * Redis is checked only when a configured driver actually uses it. A deployment
 * that keeps cache, sessions and queues in the database (the free-tier pilot)
 * reports `not_used` rather than failing on a service it does not have.
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
            Log::warning('Health check: database unreachable.', ['exception' => $e]);
            $checks['database'] = 'error';
        }

        if ($this->usesRedis()) {
            try {
                Redis::connection()->ping();
                $checks['redis'] = 'ok';
            } catch (Throwable $e) {
                Log::warning('Health check: Redis unreachable.', ['exception' => $e]);
                $checks['redis'] = 'error';
            }
        } else {
            $checks['redis'] = 'not_used';
        }

        $ok = ! in_array('error', $checks, true);

        return response()->json([
            'status' => $ok ? 'healthy' : 'degraded',
            'checks' => $checks,
        ], $ok ? 200 : 503);
    }

    private function usesRedis(): bool
    {
        return in_array('redis', [
            config('cache.stores.'.config('cache.default').'.driver'),
            config('session.driver'),
            config('queue.connections.'.config('queue.default').'.driver'),
            config('broadcasting.connections.'.config('broadcasting.default').'.driver'),
        ], true);
    }
}
