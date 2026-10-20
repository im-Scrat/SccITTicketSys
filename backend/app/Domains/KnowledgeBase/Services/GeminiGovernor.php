<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use Closure;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use RedisException;

/**
 * Phase 2.9 — the one gate every Gemini chat call passes through, so that many
 * tickets can be analysed at once **without** the application outrunning the
 * provider or itself.
 *
 * Two independent ceilings, both shared by every worker and web process
 * (they live in Redis, not in a process):
 *
 *   concurrency  at most `ai.sccit.gemini_concurrency` calls in flight at once
 *                (Redis funnel). Ticket A, B, C and D each run as their own job
 *                on their own worker; up to this many talk to Gemini in
 *                parallel, the rest wait for a slot — they are not dropped and
 *                they never block a job for a different ticket from starting.
 *   rate         at most `ai.sccit.gemini_rpm` calls started per minute, the
 *                budget the provider tier actually grants. Tunable per
 *                deployment; the default is deliberately conservative because
 *                the tier is not known to this code.
 *
 * When neither is available within `gemini_slot_wait_seconds` the call is not
 * made and {@see AiProviderException::busy()} is thrown — transient, so a queued
 * job releases itself with a backoff and an interactive caller answers "busy".
 *
 * Setting both ceilings to 0 disables the governor (the test suite does, and
 * turns it on per test where the behaviour is under test).
 *
 * A host with no Redis at all (the Render pilot runs cache and queue in
 * Postgres) must still answer: when the funnel cannot even connect, the call
 * runs under the per-minute ceiling alone instead of failing every request
 * with a 500. The intended configuration there is `AI_GEMINI_CONCURRENCY=0`;
 * this fallback covers a service whose environment was typed in by hand and
 * missed it.
 */
class GeminiGovernor
{
    private const CONCURRENCY_KEY = 'ai:gemini:concurrency';

    private const RATE_KEY = 'ai:gemini:rpm';

    private static bool $warnedFunnelUnavailable = false;

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws AiProviderException when there is no capacity in time
     */
    public function run(Closure $call): mixed
    {
        $concurrency = (int) config('ai.sccit.gemini_concurrency', 3);
        $rpm = (int) config('ai.sccit.gemini_rpm', 10);

        if ($concurrency <= 0 && $rpm <= 0) {
            return $call();
        }

        $guarded = fn (): mixed => $this->withinRate($rpm, $call);

        if ($concurrency <= 0) {
            return $guarded();
        }

        // Set once the guarded call has begun, so a Redis error raised from
        // *inside* the call is never mistaken for an unreachable funnel and
        // the call is never made twice.
        $started = false;

        try {
            return Redis::funnel(self::CONCURRENCY_KEY)
                ->limit($concurrency)
                // A worker killed mid-call must not hold its slot for ever: the
                // slot expires a little after the longest call could last.
                ->releaseAfter($this->slotLifetimeSeconds())
                ->block($this->waitSeconds())
                ->then(function () use ($guarded, &$started): mixed {
                    $started = true;

                    return $guarded();
                });
        } catch (LimiterTimeoutException) {
            throw AiProviderException::busy();
        } catch (RedisException $e) {
            if ($started) {
                throw $e;
            }

            $this->warnFunnelUnavailable($e);

            return $guarded();
        }
    }

    private function warnFunnelUnavailable(RedisException $e): void
    {
        // Once per process: every AI call would otherwise repeat the same line.
        if (self::$warnedFunnelUnavailable) {
            return;
        }

        self::$warnedFunnelUnavailable = true;

        Log::warning('Gemini concurrency funnel unavailable (Redis unreachable); running under the per-minute limit only. Set AI_GEMINI_CONCURRENCY=0 on hosts without Redis.', [
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * @param  Closure(): mixed  $call
     */
    private function withinRate(int $rpm, Closure $call): mixed
    {
        if ($rpm <= 0) {
            return $call();
        }

        // Counted when a call *starts*, so a burst of four tickets spends four
        // of the minute's budget whether or not the provider answers quickly.
        if (RateLimiter::tooManyAttempts(self::RATE_KEY, $rpm)) {
            throw AiProviderException::busy();
        }

        RateLimiter::hit(self::RATE_KEY, 60);

        return $call();
    }

    private function waitSeconds(): int
    {
        return max(0, (int) config('ai.sccit.gemini_slot_wait_seconds', 10));
    }

    private function slotLifetimeSeconds(): int
    {
        // Three attempts at the agent timeout, plus slack for backoff and I/O.
        return (int) config('ai.sccit.agent_timeout_seconds', 30) * 3 + 30;
    }
}
