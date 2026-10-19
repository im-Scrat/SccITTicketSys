<?php

declare(strict_types=1);

use App\Domains\Analytics\DTOs\OverallReportFilters;
use App\Domains\Analytics\DTOs\ReportFilters;
use App\Domains\Identity\Services\PermissionResolver;
use App\Domains\KnowledgeBase\Agents\ReportNarrativeAgent;
use App\Domains\KnowledgeBase\Services\PromptRedactor;
use App\Domains\KnowledgeBase\Services\ReportNarrativeContext;
use App\Domains\KnowledgeBase\Services\ReportNarrativeGenerator;
use App\Enums\ActivityAction;
use App\Enums\AiModality;
use App\Enums\PermissionGrantType;
use App\Models\ActivityLog;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use App\Models\ReportNarrative;
use App\Models\SystemSetting;
use App\Support\SchoolClock;
use Database\Seeders\MaintenanceTypeSeeder;
use Database\Seeders\TicketLookupSeeder;
use Tests\Support\ReportFixtures;

/*
 * Phase 2.10 R5 — AI interpretations of the overall report (SRS FR-RPT-011,
 * SDD DD-95): off by default, gated on the application's own facts before any
 * provider call, cached by content, stale when the data moves, validated
 * statement by statement, and given codes and counts only.
 */

beforeEach(function (): void {
    seedRbac();
    $this->seed([TicketLookupSeeder::class, MaintenanceTypeSeeder::class]);
    $this->travelTo(ReportFixtures::local('2026-10-18 10:00')->utc());
    $this->admin = userWithRole('administrator');

    $model = AiModel::factory()->create(['provider' => 'gemini', 'model_identifier' => 'gemini-1.5-flash', 'modality' => AiModality::Text->value]);
    AiSystemSetting::factory()->create(['active_model_id' => $model->id]);
    config(['ai.providers.gemini.key' => 'test-key']);

    $this->enable = fn (bool $on = true) => SystemSetting::query()->updateOrCreate(['key' => 'ai.report_narratives_enabled'], ['group' => 'ai', 'value' => $on, 'type' => 'boolean']);

    // Enough for q1 and q3: PC-001 twice and PC-002 once, power supplies, corrective.
    $room = ReportFixtures::room(ReportFixtures::floor(), 'laboratory', ['code' => 'LAB-A']);
    $this->pc1 = ReportFixtures::pc($room, ['unit_code' => 'PC-001']);
    $pc2 = ReportFixtures::pc($room, ['unit_code' => 'PC-002']);
    foreach ([[$this->pc1, '2026-10-03 10:00'], [$this->pc1, '2026-10-10 10:00'], [$pc2, '2026-10-12 10:00']] as [$pc, $at]) {
        $record = ReportFixtures::repair($pc, 'corrective', $at, ['cost' => '999.00', 'diagnosis' => 'SECRET-NOTE-55']);
        ReportFixtures::replacement($record, 'power_supply', 'power_supply', $at);
    }
    ReportFixtures::ticketAt(userWithRole('teacher', ['first_name' => 'Zelda', 'last_name' => 'Quintero']), '2026-10-04 09:00', 'open', [
        'room_id' => $room->id, 'pc_unit_id' => $this->pc1->id, 'asset_id' => null,
        'title' => 'SECRET-TITLE-55 no display', 'description' => 'SECRET-DESC-55',
    ]);

    $this->answer = fn (array $findings): array => [
        'coverage' => ['q1' => 'answered', 'q2' => 'insufficient_data', 'q3' => 'answered', 'q4' => 'insufficient_data', 'q5' => 'answered'],
        'findings' => $findings,
        'limitations' => ['Small samples can mislead.'],
    ];
    $this->valid = [
        'question' => 'q1', 'kind' => 'observation', 'confidence' => 'low',
        'text' => 'PC-001 had 2 corrective part replacements in the period.',
        'cited_fact_keys' => ['q1.pc_1.corrective_replacement_events'],
    ];
});

it('shows the recorded facts with AI switched off, and never calls the provider', function (): void {
    ReportNarrativeAgent::fake([($this->answer)([$this->valid])]);

    $state = $this->actingAs($this->admin)->getJson('/api/admin/reports/narratives/current?period=month')->assertOk()->json('data');
    $generated = $this->actingAs($this->admin)->postJson('/api/admin/reports/narratives', ['period' => 'month'])->assertSuccessful()->json('data');

    expect($state['enabled'])->toBeFalse()
        ->and($state['facts']['questions']['q1']['sufficient'])->toBeTrue()
        ->and($generated['status'])->toBe('disabled');
    ReportNarrativeAgent::assertNeverPrompted();
});

it('does not call the provider when no question has enough data', function (): void {
    ($this->enable)();
    ReportNarrativeAgent::fake([($this->answer)([$this->valid])]);

    $generated = $this->actingAs($this->admin)->postJson('/api/admin/reports/narratives', ['period' => 'day', 'room_type' => 'office'])->assertSuccessful()->json('data');

    expect($generated['status'])->toBe('insufficient');
    ReportNarrativeAgent::assertNeverPrompted();
});

it('interprets sufficient data, keeps only statements that meet the rules, and audits counts only', function (): void {
    ($this->enable)();
    ReportNarrativeAgent::fake([($this->answer)([
        $this->valid,
        [...$this->valid, 'text' => 'PC-001 had 9 corrective part replacements.'],
        [...$this->valid, 'kind' => 'recommendation', 'text' => 'PC-001 will certainly fail; replace it.'],
        [...$this->valid, 'question' => 'q2', 'cited_fact_keys' => ['q2.located_pc_problems']],
        [...$this->valid, 'text' => 'Details at www.example.com.'],
    ])]);

    $id = $this->actingAs($this->admin)->postJson('/api/admin/reports/narratives', ['period' => 'month'])->assertStatus(202)->json('data.id');
    $narrative = ReportNarrative::query()->where('uuid', $id)->sole();

    expect($narrative->status->value)->toBe('completed')
        ->and($narrative->findings)->toHaveCount(1)
        ->and($narrative->withheld_count)->toBe(4)
        ->and($narrative->coverage['q2'])->toBe('insufficient_data');

    $log = ActivityLog::query()->where('action', ActivityAction::ReportNarrativeGenerated->value)->sole();
    expect(json_encode($log->properties))->not->toContain('PC-001 had');
});

it('returns the same interpretation for the same data, and says when the data has changed', function (): void {
    ($this->enable)();
    ReportNarrativeAgent::fake([($this->answer)([$this->valid]), ($this->answer)([$this->valid])]);

    $first = $this->actingAs($this->admin)->postJson('/api/admin/reports/narratives', ['period' => 'month'])->json('data.id');
    $second = $this->actingAs($this->admin)->postJson('/api/admin/reports/narratives', ['period' => 'month'])->assertOk()->json('data.id');
    expect($second)->toBe($first)
        ->and(ReportNarrative::query()->count())->toBe(1);

    $record = ReportFixtures::repair($this->pc1, 'corrective', '2026-10-15 10:00');
    ReportFixtures::replacement($record, 'ram', 'ram', '2026-10-15 10:00');

    $state = $this->actingAs($this->admin)->getJson('/api/admin/reports/narratives/current?period=month')->json('data');
    expect($state['current'])->toBeFalse()
        ->and($state['changed_fact_keys'])->toContain('q1.pc_1.corrective_replacement_events');
});

it('gives the model codes and counts only — no names, ticket text, notes or costs', function (): void {
    $filters = OverallReportFilters::fromValidated(ReportFilters::fromValidated(['period' => 'month'], SchoolClock::asOf()), []);
    $packet = app(ReportNarrativeGenerator::class)->packet($filters);
    $prompt = app(ReportNarrativeContext::class)->build($packet);

    foreach (['SECRET-TITLE-55', 'SECRET-DESC-55', 'SECRET-NOTE-55', 'Zelda', 'Quintero', '999.00', $this->pc1->uuid] as $forbidden) {
        expect($prompt)->not->toContain($forbidden);
    }
    expect($prompt)->toContain('PC-001')
        ->and(app(PromptRedactor::class)->redact($prompt))->toBe($prompt)
        ->and((new ReportNarrativeAgent)->instructions())->not->toContain('PC-001');
});

it('refuses technicians, and an administrator without repair access', function (): void {
    $this->actingAs(userWithRole('technician'))->getJson('/api/admin/reports/narratives/current')->assertForbidden();

    givePermission($this->admin, 'maintenance.view', PermissionGrantType::Deny);
    app(PermissionResolver::class)->forget($this->admin);
    $this->actingAs($this->admin)->getJson('/api/admin/reports/narratives/current')->assertForbidden();
    $this->actingAs($this->admin)->postJson('/api/admin/reports/narratives')->assertForbidden();
});

it('includes the interpretation in a document only when one exists for exactly this data', function (): void {
    ($this->enable)();
    $without = $this->actingAs($this->admin)->postJson('/api/admin/reports/print', ['report_type' => 'overall', 'period' => 'month', 'include_narrative' => true])->json('data');
    $insightsWithout = collect($without['sections'])->firstWhere('key', 'insights');
    expect($insightsWithout['notes'][0])->toContain('No AI interpretation was generated for exactly this data');

    ReportNarrativeAgent::fake([($this->answer)([$this->valid])]);
    $this->actingAs($this->admin)->postJson('/api/admin/reports/narratives', ['period' => 'month']);

    $with = $this->actingAs($this->admin)->postJson('/api/admin/reports/print', ['report_type' => 'overall', 'period' => 'month', 'include_narrative' => true])->json('data');
    $insights = collect($with['sections'])->firstWhere('key', 'insights');
    expect(collect($insights['tables'])->pluck('key')->all())->toContain('interpretation')
        ->and(json_encode($insights))->toContain('PC-001 had 2 corrective part replacements');
});
