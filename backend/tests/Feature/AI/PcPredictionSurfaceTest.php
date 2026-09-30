<?php

declare(strict_types=1);

use App\Models\AiPrediction;
use App\Models\PcUnit;

/**
 * WP-M — no endpoint of the prediction surface goes unchecked.
 *
 * `PcPredictionAuthorizationTest` proves the boundary role by role for the
 * endpoints its author listed. This proves the *list* is complete: every route
 * the router exposes under `/api/admin/predictions` is exercised below, so an
 * endpoint added later without a policy check cannot ship unnoticed — the test
 * fails until someone adds it here, and adding it here is the moment its 403 is
 * asserted.
 */
beforeEach(function (): void {
    seedRbac();

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');

    $this->prediction = AiPrediction::factory()->create([
        'pc_unit_id' => PcUnit::factory()->create()->id,
        'predicted_issue' => 'DISTINCTIVE-ISSUE-TEXT',
        'explanation' => 'DISTINCTIVE-EXPLANATION-TEXT',
    ]);
    $this->missing = '11111111-2222-4333-8444-555555555555';
});

/** @return list<array{0: string, 1: string}> */
function predictionEndpoints(string $uuid): array
{
    return [
        ['GET', '/api/admin/predictions'],
        ['GET', "/api/admin/predictions/{$uuid}"],
        ['PATCH', "/api/admin/predictions/{$uuid}/confirm"],
        ['PATCH', "/api/admin/predictions/{$uuid}/dismiss"],
    ];
}

it('has exercised every route the prediction surface exposes', function (): void {
    $registered = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/admin/predictions'))
        ->flatMap(fn ($route): array => collect($route->methods())
            ->reject(fn (string $method): bool => in_array($method, ['HEAD', 'OPTIONS'], true))
            ->map(fn (string $method): string => $method.' /'.preg_replace('/\{[^}]+\}/', '{uuid}', $route->uri()))
            ->all())
        ->sort()->values()->all();

    $covered = collect(predictionEndpoints('{uuid}'))
        ->map(fn (array $endpoint): string => $endpoint[0].' '.$endpoint[1])
        ->sort()->values()->all();

    expect($registered)->toBe($covered);
});

it('refuses a technician and a teacher every endpoint, for a real and for a made-up uuid alike', function (): void {
    foreach ([$this->technician, $this->teacher] as $actor) {
        foreach ([$this->prediction->uuid, $this->missing] as $uuid) {
            foreach (predictionEndpoints($uuid) as [$method, $path]) {
                $this->actingAs($actor)->json($method, $path)->assertForbidden();
            }
        }
    }
});

it('puts nothing of the finding in a refusal', function (): void {
    // Asserted against the *encoded* response, so a field cannot leak back in
    // through a later change to a resource or an exception handler.
    foreach ([$this->technician, $this->teacher] as $actor) {
        foreach (predictionEndpoints($this->prediction->uuid) as [$method, $path]) {
            $body = $this->actingAs($actor)->json($method, $path)->assertForbidden()->getContent();

            expect($body)
                ->not->toContain('DISTINCTIVE-ISSUE-TEXT')
                ->not->toContain('DISTINCTIVE-EXPLANATION-TEXT')
                ->not->toContain($this->prediction->uuid);
        }
    }
});

it('never lets a numeric id address a finding', function (): void {
    $this->actingAs($this->admin)->getJson("/api/admin/predictions/{$this->prediction->id}")->assertNotFound();
});

it('never exposes an internal identifier, even to the administrator', function (): void {
    $detail = $this->actingAs($this->admin)->getJson("/api/admin/predictions/{$this->prediction->uuid}")->assertOk()->getContent();
    $list = $this->actingAs($this->admin)->getJson('/api/admin/predictions')->assertOk()->getContent();

    foreach (['"pc_unit_id"', '"ai_model_id"', '"ai_failure_pattern_id"'] as $forbidden) {
        expect($detail)->not->toContain($forbidden)->and($list)->not->toContain($forbidden);
    }
});

it('leaves the finding untouched when a refused caller tries to decide it', function (): void {
    foreach ([$this->technician, $this->teacher] as $actor) {
        $this->actingAs($actor)->patchJson("/api/admin/predictions/{$this->prediction->uuid}/confirm")->assertForbidden();
        $this->actingAs($actor)->patchJson("/api/admin/predictions/{$this->prediction->uuid}/dismiss")->assertForbidden();
    }

    expect($this->prediction->refresh()->status->value)->toBe('pending');
});
