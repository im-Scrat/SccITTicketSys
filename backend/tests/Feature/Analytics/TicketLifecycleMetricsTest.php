<?php

declare(strict_types=1);

use App\Domains\Analytics\Services\TicketLifecycleMetrics as M;
use App\Domains\Tickets\Services\TicketLifecycle;

/*
 * Phase 2.10 — every lifecycle edge means something to the reports (SDD DD-88).
 *
 * Walks TicketLifecycle's own transition map, so a transition added there
 * later cannot fall outside every report figure without this failing.
 */

it('classifies every transition in the lifecycle map', function (): void {
    $map = (new ReflectionClassConstant(TicketLifecycle::class, 'TRANSITIONS'))->getValue();
    $kinds = [M::KIND_RESOLUTION, M::KIND_CONFIRMATION, M::KIND_SENT_BACK, M::KIND_CLOSED_WITHOUT_RESOLUTION, M::KIND_CANCELLATION, M::KIND_PROGRESS];
    $edges = 0;

    foreach ($map as $from => $targets) {
        foreach (array_keys($targets) as $to) {
            expect(M::classify($from, $to))->toBeIn($kinds, "{$from} → {$to} has no report meaning");
            $edges++;
        }
    }

    expect($edges)->toBeGreaterThan(15);
});

it('gives each kind of move the meaning the reports rely on', function (?string $from, string $to, string $kind): void {
    expect(M::classify($from, $to))->toBe($kind);
})->with([
    'opening row' => [null, 'open', M::KIND_CREATION],
    'staff fix' => ['in-progress', 'resolved', M::KIND_RESOLUTION],
    "reporter's own FIXED" => ['open', 'resolved', M::KIND_RESOLUTION],
    'reporter confirms' => ['resolved', 'closed', M::KIND_CONFIRMATION],
    'reporter says not fixed' => ['resolved', 'open', M::KIND_SENT_BACK],
    'technician takes it back' => ['resolved', 'in-progress', M::KIND_SENT_BACK],
    'reopened after closing' => ['closed', 'open', M::KIND_SENT_BACK],
    'closed without a fix' => ['open', 'closed', M::KIND_CLOSED_WITHOUT_RESOLUTION],
    'withdrawn' => ['assigned', 'cancelled', M::KIND_CANCELLATION],
    'put on hold' => ['in-progress', 'on-hold', M::KIND_PROGRESS],
]);
