<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\DTOs\PcRiskAssessment;
use App\Domains\KnowledgeBase\Events\PcPredictionGenerated;
use App\Domains\KnowledgeBase\Services\PcRiskAssessor;
use App\Enums\ActivityAction;
use App\Enums\MaintenanceStatus;
use App\Enums\NotificationType;
use App\Enums\PredictionRiskLevel;
use App\Enums\PredictionStatus;
use App\Models\ActivityLog;
use App\Models\AiFailurePattern;
use App\Models\AiModel;
use App\Models\AiPrediction;
use App\Models\AiSystemSetting;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Notification as NotificationRecord;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\MaintenanceTypeSeeder;
use Illuminate\Support\Collection;

/**
 * WP-M — the Admin predictive-maintenance review surface itself: the list,
 * the detail (the mandate's field list), the confirm/dismiss decision, and
 * the administrator notification that tells them a finding is waiting.
 */
beforeEach(function (): void {
    seedRbac();
    $this->seed(MaintenanceTypeSeeder::class);
    $this->admin = userWithRole('administrator');
    $this->otherAdmin = userWithRole('administrator');
    $this->technician = userWithRole('technician');

    $this->building = Building::factory()->create(['name' => 'Science Hall']);
    $this->floor = Floor::factory()->create(['building_id' => $this->building->id, 'name' => 'Second Floor']);
    $this->room = Room::factory()->create(['floor_id' => $this->floor->id, 'name' => 'Lab 3']);
    $this->pcUnit = PcUnit::factory()->create([
        'room_id' => $this->room->id,
        'unit_code' => 'PC-WPM-01',
        'pc_name' => 'Lab 3 Workstation',
        'asset_tag' => 'AST-9001',
    ]);
});

function predictionInboxOf(User $user): Collection
{
    return NotificationRecord::query()->where('user_id', $user->id)->orderBy('id')->get();
}

/** A pending finding with its supporting pattern, model and evidence. */
function wpmPrediction(PcUnit $pcUnit, array $overrides = []): AiPrediction
{
    $model = AiModel::factory()->create(['provider' => 'gemini', 'model_identifier' => 'gemini-1.5-flash']);
    $pattern = AiFailurePattern::factory()->create([
        'pc_unit_id' => $pcUnit->id,
        'pattern_name' => 'Recurring Power Supply replacement',
        'occurrence_count' => 3,
        'average_days_between_failures' => 60,
    ]);

    return AiPrediction::factory()->create([
        'pc_unit_id' => $pcUnit->id,
        'ai_model_id' => $model->id,
        'ai_failure_pattern_id' => $pattern->id,
        'predicted_issue' => 'Power supply failure',
        'risk_level' => PredictionRiskLevel::High->value,
        'probability' => null,
        'confidence' => 0.72,
        'predicted_within_days' => 50,
        'explanation' => 'Three power supply replacements at regular ~60-day intervals.',
        'recommendation' => 'Inspect ventilation before the next interval elapses.',
        'evidence' => [
            'observed' => [
                'completed_repairs' => 3,
                'corrective_repairs' => 3,
                'preventive_visits' => 0,
                'first_completed_at' => '2026-05-01T00:00:00+00:00',
                'last_completed_at' => '2026-08-20T00:00:00+00:00',
                'components_replaced' => [
                    ['component_type' => 'power_supply', 'label' => 'Power Supply', 'count' => 3],
                ],
            ],
            'patterns' => [
                [
                    'kind' => 'component',
                    'name' => 'Recurring Power Supply replacement',
                    'detected_problem' => 'Power Supply replaced in 3 separate repairs',
                    'occurrence_count' => 3,
                    'intervals_days' => [60, 60],
                    'average_days_between' => 60,
                    'first_at' => '2026-05-01T00:00:00+00:00',
                    'last_at' => '2026-08-20T00:00:00+00:00',
                    'records' => [],
                ],
            ],
            'time_window' => ['days' => 50, 'basis' => "The strongest pattern's average interval, less the days since its last occurrence."],
        ],
        'status' => PredictionStatus::Pending->value,
        'generated_at' => now(),
        ...$overrides,
    ]);
}

/* ------------------------------------------------------------------ list */

it('lists findings pending first, filterable by status and risk level', function (): void {
    $pending = wpmPrediction($this->pcUnit);
    $confirmed = wpmPrediction(PcUnit::factory()->create(['room_id' => $this->room->id]), ['status' => PredictionStatus::Confirmed->value]);
    $lowRisk = wpmPrediction(PcUnit::factory()->create(['room_id' => $this->room->id]), ['risk_level' => PredictionRiskLevel::Low->value]);

    $all = $this->actingAs($this->admin)->getJson('/api/admin/predictions')->assertOk();
    expect($all->json('data.0.id'))->toBe($pending->uuid) // pending sorts first
        ->and(collect($all->json('data'))->pluck('id'))->toContain($confirmed->uuid, $lowRisk->uuid);

    $this->actingAs($this->admin)->getJson('/api/admin/predictions?status=confirmed')
        ->assertOk()
        ->assertJsonPath('data.0.id', $confirmed->uuid)
        ->assertJsonCount(1, 'data');

    $this->actingAs($this->admin)->getJson('/api/admin/predictions?risk_level=low')
        ->assertOk()
        ->assertJsonPath('data.0.id', $lowRisk->uuid)
        ->assertJsonCount(1, 'data');
});

it('refuses an unrecognized status or risk level rather than silently ignoring it', function (): void {
    $this->actingAs($this->admin)->getJson('/api/admin/predictions?status=made-up')
        ->assertUnprocessable();
});

/* ---------------------------------------------------------------- detail */

it('serves every field the mandate asks for', function (): void {
    $prediction = wpmPrediction($this->pcUnit);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/admin/predictions/{$prediction->uuid}")
        ->assertOk();

    $response
        // PC / asset / location.
        ->assertJsonPath('data.pc_unit.label', 'Lab 3 Workstation')
        ->assertJsonPath('data.pc_unit.identifier', 'PC-WPM-01')
        ->assertJsonPath('data.pc_unit.asset_tag', 'AST-9001')
        ->assertJsonPath('data.location.room', 'Lab 3')
        ->assertJsonPath('data.location.floor', 'Second Floor')
        ->assertJsonPath('data.location.building', 'Science Hall')
        // Predicted issue / risk / confidence / probability / window / explanation / recommendation.
        ->assertJsonPath('data.predicted_issue', 'Power supply failure')
        ->assertJsonPath('data.risk_level.value', 'high')
        ->assertJsonPath('data.risk_level.label', 'High')
        ->assertJsonPath('data.confidence', 0.72)
        ->assertJsonPath('data.probability', null)
        ->assertJsonPath('data.predicted_within_days', 50)
        ->assertJsonPath('data.explanation', 'Three power supply replacements at regular ~60-day intervals.')
        ->assertJsonPath('data.recommendation', 'Inspect ventilation before the next interval elapses.')
        // Evidence: previous problems / completed repair count / repeating problems /
        // recent repair / components replaced / detected pattern — kept apart, not prose.
        ->assertJsonPath('data.evidence.observed.completed_repairs', 3)
        ->assertJsonPath('data.evidence.observed.corrective_repairs', 3)
        ->assertJsonPath('data.evidence.observed.last_completed_at', '2026-08-20T00:00:00+00:00')
        ->assertJsonPath('data.evidence.observed.components_replaced.0.component_type', 'power_supply')
        ->assertJsonPath('data.evidence.patterns.0.name', 'Recurring Power Supply replacement')
        ->assertJsonPath('data.evidence.patterns.0.occurrence_count', 3)
        ->assertJsonPath('data.failure_pattern.name', 'Recurring Power Supply replacement')
        ->assertJsonPath('data.status.value', 'pending')
        ->assertJsonPath('data.can.decide', true);
});

it('never claims a time window the evidence does not support', function (): void {
    $prediction = wpmPrediction($this->pcUnit, [
        'predicted_within_days' => null,
        'evidence' => ['observed' => [], 'patterns' => [], 'time_window' => ['days' => null, 'basis' => 'Not stated.']],
    ]);

    $this->actingAs($this->admin)
        ->getJson("/api/admin/predictions/{$prediction->uuid}")
        ->assertOk()
        ->assertJsonPath('data.predicted_within_days', null)
        ->assertJsonPath('data.evidence.time_window.days', null);
});

it('says can.decide is false once a finding is already decided', function (): void {
    $prediction = wpmPrediction($this->pcUnit, ['status' => PredictionStatus::Confirmed->value]);

    // `manage` is class-level (no per-record scoping) — `can.decide` reflects
    // whether the actor holds the ability at all, not this row's own status.
    // The status transition itself is what the write endpoints refuse below.
    $this->actingAs($this->admin)
        ->getJson("/api/admin/predictions/{$prediction->uuid}")
        ->assertOk()
        ->assertJsonPath('data.can.decide', true)
        ->assertJsonPath('data.status.value', 'confirmed');
});

/* --------------------------------------------------------------- decide */

it('confirms a pending finding, records who decided it, and does not touch the machine', function (): void {
    $prediction = wpmPrediction($this->pcUnit);

    $this->actingAs($this->admin)
        ->patchJson("/api/admin/predictions/{$prediction->uuid}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status.value', 'confirmed');

    expect($prediction->fresh()->status)->toBe(PredictionStatus::Confirmed)
        ->and($this->pcUnit->fresh()->status)->toBe($this->pcUnit->status);

    $log = ActivityLog::query()->where('action', ActivityAction::PcPredictionConfirmed->value)->sole();
    expect($log->user_id)->toBe($this->admin->id)
        ->and($log->subject_id)->toBe($prediction->id);
});

it('dismisses a pending finding and records who decided it', function (): void {
    $prediction = wpmPrediction($this->pcUnit);

    $this->actingAs($this->admin)
        ->patchJson("/api/admin/predictions/{$prediction->uuid}/dismiss")
        ->assertOk()
        ->assertJsonPath('data.status.value', 'dismissed');

    $log = ActivityLog::query()->where('action', ActivityAction::PcPredictionDismissed->value)->sole();
    expect($log->user_id)->toBe($this->admin->id);
});

it('refuses to decide a finding twice', function (): void {
    $prediction = wpmPrediction($this->pcUnit, ['status' => PredictionStatus::Confirmed->value]);

    $this->actingAs($this->admin)
        ->patchJson("/api/admin/predictions/{$prediction->uuid}/dismiss")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($prediction->fresh()->status)->toBe(PredictionStatus::Confirmed);
});

it('refuses to decide a finding that was superseded before anyone acted on it', function (): void {
    $prediction = wpmPrediction($this->pcUnit, ['status' => PredictionStatus::Expired->value]);

    $this->actingAs($this->admin)
        ->patchJson("/api/admin/predictions/{$prediction->uuid}/confirm")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

/* ---------------------------------------------------------- notification */

it('notifies every administrator, and only administrators, when a finding is generated', function (): void {
    $prediction = wpmPrediction($this->pcUnit);
    event(new PcPredictionGenerated($prediction));

    $adminInbox = predictionInboxOf($this->admin);
    expect($adminInbox)->toHaveCount(1)
        ->and($adminInbox->first()->data['topic'])->toBe('maintenance.prediction_generated')
        ->and($adminInbox->first()->type)->toBe(NotificationType::Maintenance)
        ->and($adminInbox->first()->title)->toContain('PC-WPM-01')
        ->and($adminInbox->first()->action_url)->toBe("/app/predictions/{$prediction->uuid}");

    expect(predictionInboxOf($this->otherAdmin))->toHaveCount(1);
    expect(predictionInboxOf($this->technician))->toHaveCount(0);
});

it('does not tell anybody about a background run that found nothing new', function (): void {
    // A real end-to-end assessment where the evidence is insufficient
    // (PcRiskAssessor::assess() never reaches the `predicted` outcome, so
    // PcPredictionGenerated is never raised) must notify nobody.
    $model = AiModel::factory()->create(['provider' => 'gemini']);
    AiSystemSetting::factory()->create(['active_model_id' => $model->id, 'enable_predictions' => true]);
    config(['ai.providers.gemini.key' => 'test-key']);

    $technician = userWithRole('technician');
    maintenanceFor($technician, 'corrective', [
        'pc_unit_id' => $this->pcUnit->id,
        'status' => MaintenanceStatus::Completed->value,
        'completed_at' => now()->subDays(10),
    ]);

    $assessment = app(PcRiskAssessor::class)->assess($this->pcUnit);

    expect($assessment->outcome)->toBe(PcRiskAssessment::INSUFFICIENT)
        ->and(predictionInboxOf($this->admin))->toHaveCount(0);
});
