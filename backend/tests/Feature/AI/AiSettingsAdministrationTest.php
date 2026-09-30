<?php

declare(strict_types=1);

use App\Domains\KnowledgeBase\Agents\ConnectionCheckAgent;
use App\Enums\ActivityAction;
use App\Enums\AiModality;
use App\Enums\PermissionGrantType;
use App\Models\ActivityLog;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use App\Models\SystemSetting;
use Database\Seeders\SystemSettingSeeder;
use Laravel\Ai\Embeddings;

/**
 * AI administration (SRS FR-AI-014/015/033; SDD DD-74): Administrator-only,
 * credential-free, audited.
 *
 * The negative cases are the point. Every other role — including a Technician
 * individually granted `ai.configure` — and guests are refused on every route,
 * because the surface is closed by the policy and not by the permission string.
 */
beforeEach(function () {
    seedRbac();
    $this->seed(SystemSettingSeeder::class);

    $this->chat = AiModel::factory()->create(['name' => 'Chat A', 'provider' => 'gemini', 'model_identifier' => 'chat-a', 'modality' => AiModality::Text->value]);
    $this->embed = AiModel::factory()->embedding()->create(['name' => 'Embed A', 'provider' => 'gemini', 'model_identifier' => 'embed-a', 'modality' => AiModality::Embedding->value, 'embedding_dimensions' => 768]);
    AiSystemSetting::factory()->create(['active_model_id' => $this->chat->id, 'embedding_model_id' => $this->embed->id, 'confidence_threshold' => 0.7]);

    $this->admin = userWithRole('administrator');
    $this->technician = userWithRole('technician');
    $this->teacher = userWithRole('teacher');
});

function aiSettingsPayload($test, array $overrides = []): array
{
    return [
        'active_model' => $test->chat->uuid,
        'embedding_model' => $test->embed->uuid,
        'confidence_threshold' => 0.7,
        'enable_predictions' => false,
        'enable_learning' => false,
        'auto_generate_articles' => false,
        'assistant_enabled' => true,
        ...$overrides,
    ];
}

it('closes every route to every role but the administrator', function () {
    foreach ([$this->technician, $this->teacher] as $user) {
        $this->actingAs($user)->getJson('/api/admin/ai/settings')->assertForbidden();
        $this->actingAs($user)->putJson('/api/admin/ai/settings', aiSettingsPayload($this))->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/ai/models', ['name' => 'x', 'model_identifier' => 'x', 'modality' => 'text'])->assertForbidden();
        $this->actingAs($user)->putJson("/api/admin/ai/models/{$this->chat->uuid}", ['is_active' => false])->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/ai/test')->assertForbidden();
    }

    // The same refusal for a model uuid that does not exist — no existence oracle.
    $this->actingAs($this->teacher)->putJson('/api/admin/ai/models/'.fake()->uuid(), ['is_active' => false])->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/admin/ai/settings')->assertUnauthorized();
});

it('does not open to a technician who is individually granted ai.configure', function () {
    givePermission($this->technician, 'ai.configure');

    $this->actingAs($this->technician)->getJson('/api/admin/ai/settings')->assertForbidden();
    $this->actingAs($this->technician)->putJson('/api/admin/ai/settings', aiSettingsPayload($this))->assertForbidden();
});

it('honours a per-user deny on an administrator', function () {
    givePermission($this->admin, 'ai.configure', PermissionGrantType::Deny);

    $this->actingAs($this->admin)->getJson('/api/admin/ai/settings')->assertForbidden();
});

it('shows the transparency read without any key material', function () {
    config(['ai.providers.gemini.key' => 'this-must-never-appear-in-a-response']);

    $response = $this->actingAs($this->admin)->getJson('/api/admin/ai/settings')->assertOk();

    expect($response->json('data.ai_enabled'))->toBeTrue()
        ->and($response->json('data.provider_configured'))->toBeTrue()
        ->and($response->json('data.settings.active_model'))->toBe($this->chat->uuid)
        ->and($response->json('data.models'))->toHaveCount(2)
        ->and($response->json('data.data_sent_to_provider'))->not->toBeEmpty()
        ->and($response->json('data.data_never_sent'))->not->toBeEmpty()
        ->and($response->json('data.credential'))->toHaveKeys(['source', 'file_exists', 'file_readable', 'file_empty']);

    expect($response->getContent())->not->toContain('this-must-never-appear-in-a-response');
});

it('updates the settings and the assistant switch, and audits both', function () {
    $this->actingAs($this->admin)->putJson('/api/admin/ai/settings', aiSettingsPayload($this, [
        'confidence_threshold' => 0.85,
        'enable_predictions' => true,
        'enable_learning' => true,
        'assistant_enabled' => false,
    ]))->assertOk()->assertJsonPath('data.settings.enable_predictions', true)->assertJsonPath('data.settings.assistant_enabled', false);

    $row = AiSystemSetting::query()->first();
    expect((float) $row->confidence_threshold)->toBe(0.85)
        ->and($row->enable_predictions)->toBeTrue()
        ->and($row->enable_learning)->toBeTrue()
        ->and($row->updated_by)->toBe($this->admin->id);
    expect(filter_var(SystemSetting::query()->where('key', 'ai.assistant_enabled')->first()->value, FILTER_VALIDATE_BOOLEAN))->toBeFalse();

    $log = ActivityLog::query()->where('action', ActivityAction::AiSettingsUpdated->value)->firstOrFail();
    expect($log->properties['before']['enable_predictions'])->toBeFalse()
        ->and($log->properties['after']['enable_predictions'])->toBeTrue()
        ->and($log->properties['after']['assistant_enabled'])->toBeFalse();
});

it('switches AI off when the chat model is cleared', function () {
    $this->actingAs($this->admin)->putJson('/api/admin/ai/settings', aiSettingsPayload($this, ['active_model' => null]))
        ->assertOk()->assertJsonPath('data.ai_enabled', false);
});

it('refuses a model of the wrong kind, or a deactivated one', function () {
    $this->actingAs($this->admin)->putJson('/api/admin/ai/settings', aiSettingsPayload($this, ['active_model' => $this->embed->uuid]))->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/admin/ai/settings', aiSettingsPayload($this, ['embedding_model' => $this->chat->uuid]))->assertUnprocessable();

    $spare = AiModel::factory()->create(['is_active' => false, 'modality' => AiModality::Text->value]);
    $this->actingAs($this->admin)->putJson('/api/admin/ai/settings', aiSettingsPayload($this, ['active_model' => $spare->uuid]))->assertUnprocessable();
});

it('validates every field', function () {
    $this->actingAs($this->admin)->putJson('/api/admin/ai/settings', aiSettingsPayload($this, ['confidence_threshold' => 1.5]))->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/admin/ai/settings', ['confidence_threshold' => 0.5])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/admin/ai/settings', aiSettingsPayload($this, ['active_model' => 'not-a-uuid']))->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/admin/ai/settings', aiSettingsPayload($this, ['active_model' => fake()->uuid()]))->assertUnprocessable();
});

it('registers a model without a deploy and refuses unusable ones', function () {
    $this->actingAs($this->admin)->postJson('/api/admin/ai/models', [
        'name' => 'Next Flash', 'model_identifier' => 'next-flash', 'modality' => 'text',
    ])->assertCreated();

    expect(AiModel::query()->where('model_identifier', 'next-flash')->exists())->toBeTrue();
    expect(ActivityLog::query()->where('action', ActivityAction::AiModelRegistered->value)->count())->toBe(1);

    // An embedding model that cannot produce 768 dimensions cannot fill the vector column.
    $this->actingAs($this->admin)->postJson('/api/admin/ai/models', ['name' => 'Wide', 'model_identifier' => 'wide', 'modality' => 'embedding', 'embedding_dimensions' => 1024])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson('/api/admin/ai/models', ['name' => 'NoDim', 'model_identifier' => 'nodim', 'modality' => 'embedding'])->assertUnprocessable();
    // The identifier ends up in a provider URL path: only real model-id characters.
    $this->actingAs($this->admin)->postJson('/api/admin/ai/models', ['name' => 'Bad', 'model_identifier' => 'flash; drop table', 'modality' => 'text'])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson('/api/admin/ai/models', ['name' => 'Chat A', 'model_identifier' => 'dup', 'modality' => 'text'])->assertUnprocessable();
});

it('will not deactivate a model currently in use, and can rename or repoint an idle one', function () {
    $this->actingAs($this->admin)->putJson("/api/admin/ai/models/{$this->chat->uuid}", ['is_active' => false])->assertUnprocessable();

    $idle = AiModel::factory()->create(['name' => 'Idle', 'modality' => AiModality::Text->value]);
    $this->actingAs($this->admin)->putJson("/api/admin/ai/models/{$idle->uuid}", ['model_identifier' => 'idle-v2', 'is_active' => false])->assertOk();

    expect($idle->refresh()->model_identifier)->toBe('idle-v2')->and($idle->is_active)->toBeFalse();
});

it('tests the connection with a real-shaped call and reports each half', function () {
    config(['ai.providers.gemini.key' => 'test-key']);
    ConnectionCheckAgent::fake(['ok']);
    Embeddings::fake();

    $response = $this->actingAs($this->admin)->postJson('/api/admin/ai/test')->assertOk();

    expect($response->json('data.ok'))->toBeTrue()
        ->and($response->json('data.chat.ok'))->toBeTrue()
        ->and($response->json('data.embedding.dimensions'))->toBe(768);
    expect($response->getContent())->not->toContain('test-key');
});

it('reports — without calling anything — that no key is configured', function () {
    config(['ai.providers.gemini.key' => null]);
    ConnectionCheckAgent::fake();

    $this->actingAs($this->admin)->postJson('/api/admin/ai/test')->assertOk()->assertJsonPath('data.ok', false);

    ConnectionCheckAgent::assertNotPrompted(fn () => true);
});
