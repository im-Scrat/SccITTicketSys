<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
 * Infrastructure smoke-test (not a business feature). Confirms the app
 * can reach PostgreSQL and Redis. Safe to keep; remove if undesired.
 */
Route::get('/health', function () {
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
});
