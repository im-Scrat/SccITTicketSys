<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Http\Controllers\Admin;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\KnowledgeBase\Agents\ConnectionCheckAgent;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Domains\KnowledgeBase\Http\Requests\StoreAiModelRequest;
use App\Domains\KnowledgeBase\Http\Requests\UpdateAiSettingsRequest;
use App\Domains\KnowledgeBase\Services\AiSettings;
use App\Domains\KnowledgeBase\Services\EmbeddingGenerator;
use App\Domains\KnowledgeBase\Services\GeminiCredential;
use App\Domains\KnowledgeBase\Services\SafeAgentInvoker;
use App\Enums\ActivityAction;
use App\Enums\AiModality;
use App\Enums\EmbeddingStatus;
use App\Http\Controllers\Controller;
use App\Models\AiAnalysisLog;
use App\Models\AiEmbeddingSource;
use App\Models\AiFeedback;
use App\Models\AiModel;
use App\Models\AiSystemSetting;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Administrator controls for the AI layer (SRS FR-AI-014/015/033; SDD §22.1, DD-74).
 *
 * Every route is closed by the `AiSystemSettingPolicy` (Administrator role
 * **and** `ai.configure`) — see `AiAdministrationAccess` for why it is never the
 * permission string. What this edits is the `ai_system_settings` singleton, the
 * `ai_models` registry and the assistant switch: the one place AI *behaviour* is
 * configured, exactly as `AiSettings` reads it.
 *
 * ── What it cannot do ──────────────────────────────────────────────────────
 *
 * It cannot read, write or display the provider credential. The screen learns
 * only **whether** a key is present and whether the secret file is readable
 * ({@see GeminiCredential::status()}); the key itself is supplied on the server,
 * as a Docker secret, and appears nowhere a browser can reach.
 *
 * Without this screen the opt-in features would be unreachable: predictions,
 * learning and the model choice had no HTTP surface, so an administrator could
 * only have enabled them by editing the database.
 */
class AiSettingsController extends Controller
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        $this->authorize('viewAny', AiSystemSetting::class);

        return response()->json(['data' => $this->payload()]);
    }

    public function update(UpdateAiSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $active = isset($data['active_model']) ? AiModel::query()->where('uuid', $data['active_model'])->firstOrFail() : null;
        $embedding = isset($data['embedding_model']) ? AiModel::query()->where('uuid', $data['embedding_model'])->firstOrFail() : null;

        // A model of the wrong kind in a slot would fail every call with an
        // error nobody could trace back to a settings choice.
        abort_if($active !== null && $active->modality === AiModality::Embedding, 422, 'The chat model must be a text model.');
        abort_if($embedding !== null && $embedding->modality !== AiModality::Embedding, 422, 'The embedding model must be an embedding model.');
        abort_if($active !== null && ! $active->is_active, 422, 'That chat model is deactivated.');
        abort_if($embedding !== null && ! $embedding->is_active, 422, 'That embedding model is deactivated.');

        /** @var User $actor */
        $actor = $request->user();

        $row = AiSystemSetting::query()->first() ?? new AiSystemSetting;
        $tracked = ['active_model_id', 'embedding_model_id', 'confidence_threshold', 'enable_predictions', 'enable_learning', 'auto_generate_articles'];
        $before = $row->exists ? $row->only($tracked) : [];
        $assistantBefore = $this->settings->assistantEnabled();

        DB::transaction(function () use ($row, $active, $embedding, $data, $actor): void {
            $row->forceFill([
                'active_model_id' => $active?->getKey(),
                'embedding_model_id' => $embedding?->getKey(),
                'confidence_threshold' => $data['confidence_threshold'],
                'enable_predictions' => $data['enable_predictions'],
                'enable_learning' => $data['enable_learning'],
                'auto_generate_articles' => $data['auto_generate_articles'],
                'updated_by' => $actor->getKey(),
            ])->save();

            // The assistant switch lives in `system_settings` (seeded, public).
            SystemSetting::query()->updateOrCreate(
                ['key' => 'ai.assistant_enabled'],
                ['group' => 'ai', 'label' => 'AI Assistant Enabled', 'value' => (bool) $data['assistant_enabled'], 'type' => 'boolean', 'is_public' => true, 'updated_by' => $actor->getKey()],
            );
        });

        $this->audit->activity(
            ActivityAction::AiSettingsUpdated,
            actor: $actor,
            subject: $row,
            properties: [
                'before' => [...$before, 'assistant_enabled' => $assistantBefore],
                'after' => [...$row->only($tracked), 'assistant_enabled' => (bool) $data['assistant_enabled']],
            ],
            request: $request,
            module: 'ai',
            description: 'AI settings updated',
        );

        return response()->json(['data' => $this->payload(), 'message' => 'AI settings saved.']);
    }

    public function storeModel(StoreAiModelRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();

        $model = AiModel::query()->create([
            'name' => $data['name'],
            'provider' => 'gemini',
            'model_identifier' => $data['model_identifier'],
            'modality' => $data['modality'],
            'embedding_dimensions' => $data['modality'] === AiModality::Embedding->value ? 768 : null,
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->audit->activity(ActivityAction::AiModelRegistered, $actor, $model, ['model_identifier' => $model->model_identifier, 'modality' => $data['modality']], $request, 'ai', "AI model {$model->name} registered");

        return response()->json(['data' => $this->payload(), 'message' => 'Model registered.'], 201);
    }

    /**
     * `{model}` is a plain string for the same reason `{prediction}` is: bound
     * before the gate it would turn an unknown uuid into a 404 for callers who
     * are about to be refused with a 403.
     */
    public function updateModel(Request $request, string $model): JsonResponse
    {
        $this->authorize('manage', AiSystemSetting::class);

        $registry = AiModel::query()->where('uuid', $model)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('ai_models', 'name')->ignore($registry->getKey())],
            'model_identifier' => ['sometimes', 'string', 'max:160', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $row = AiSystemSetting::query()->first();
        $inUse = $row !== null && in_array($registry->getKey(), [$row->active_model_id, $row->embedding_model_id], true);

        abort_if(($data['is_active'] ?? true) === false && $inUse, 422, 'Select a different model in the AI settings before deactivating the one in use.');

        $registry->fill($data)->save();

        $this->audit->activity(ActivityAction::AiModelUpdated, $actor, $registry, $data, $request, 'ai', "AI model {$registry->name} updated");

        return response()->json(['data' => $this->payload(), 'message' => 'Model updated.']);
    }

    /**
     * "Test connection": one real chat call and one real embedding call through
     * the same invoker and generator production uses — the in-app twin of
     * `php artisan ai:check`. It reports whether each succeeded, never a key and
     * never a provider response body; failures carry this codebase's own static
     * messages.
     */
    public function test(SafeAgentInvoker $invoker, EmbeddingGenerator $embedder): JsonResponse
    {
        $this->authorize('manage', AiSystemSetting::class);

        if (! $this->settings->hasProviderKey('gemini')) {
            return response()->json(['data' => [
                'ok' => false,
                'message' => 'No API key is configured on the server. The key is supplied as a server secret, not here.',
                'chat' => null,
                'embedding' => null,
            ]]);
        }

        $chat = null;
        $embedding = null;

        try {
            $reply = $invoker->invoke(new ConnectionCheckAgent, 'ping', $this->settings->activeModel());
            $chat = ['ok' => true, 'model' => $reply->model, 'latency_ms' => $reply->latencyMs];
        } catch (AiUnavailableException|AiProviderException $exception) {
            $chat = ['ok' => false, 'message' => $exception->getMessage()];
        }

        try {
            $vector = $embedder->embed('connection check');
            $embedding = ['ok' => true, 'dimensions' => count($vector)];
        } catch (AiUnavailableException|AiProviderException $exception) {
            $embedding = ['ok' => false, 'message' => $exception->getMessage()];
        }

        $ok = $chat['ok'] && $embedding['ok'];

        return response()->json(['data' => [
            'ok' => $ok,
            'message' => $ok ? 'Connected to the AI provider.' : 'The AI provider check did not fully succeed.',
            'chat' => $chat,
            'embedding' => $embedding,
        ]]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        // Read fresh: `AiSettings` holds no cache, so what is returned here is
        // exactly what the next AI call will use.
        $row = AiSystemSetting::query()->with(['activeModel', 'embeddingModel'])->first();
        $credential = GeminiCredential::status();

        return [
            'ai_enabled' => $row?->activeModel !== null,
            'provider_configured' => $this->settings->hasProviderKey('gemini'),
            'credential' => [
                'source' => $credential['source'],
                'path' => $credential['path'],
                'file_exists' => $credential['file_exists'],
                'file_readable' => $credential['file_readable'],
                'file_empty' => $credential['file_empty'],
            ],
            'settings' => [
                'active_model' => $row?->activeModel?->uuid,
                'embedding_model' => $row?->embeddingModel?->uuid,
                'confidence_threshold' => $row?->confidence_threshold !== null ? (float) $row->confidence_threshold : 0.7,
                'enable_predictions' => (bool) $row?->enable_predictions,
                'enable_learning' => (bool) $row?->enable_learning,
                'auto_generate_articles' => (bool) $row?->auto_generate_articles,
                'assistant_enabled' => $this->settings->assistantEnabled(),
            ],
            'models' => AiModel::query()->orderBy('modality')->orderBy('name')->get()->map(fn (AiModel $model): array => [
                'id' => $model->uuid,
                'name' => $model->name,
                'provider' => $model->provider,
                'model_identifier' => $model->model_identifier,
                'modality' => $model->modality->value,
                'embedding_dimensions' => $model->embedding_dimensions,
                'is_active' => $model->is_active,
            ])->all(),
            'index' => [
                'indexed' => AiEmbeddingSource::query()->where('embedding_status', EmbeddingStatus::Indexed->value)->count(),
                'waiting' => AiEmbeddingSource::query()->whereIn('embedding_status', [EmbeddingStatus::Pending->value, EmbeddingStatus::Stale->value, EmbeddingStatus::Processing->value])->count(),
                'failed' => AiEmbeddingSource::query()->where('embedding_status', EmbeddingStatus::Failed->value)->count(),
            ],
            'quality' => [
                'analyses' => AiAnalysisLog::query()->count(),
                'feedback_helpful' => AiFeedback::query()->where('was_helpful', true)->count(),
                'feedback_total' => AiFeedback::query()->whereNotNull('was_helpful')->count(),
            ],
            // FR-AI-033: what the provider is sent, in words an administrator can
            // read out to a governing body.
            'data_sent_to_provider' => [
                'Ticket title and description, with names, email addresses and phone numbers removed.',
                'The equipment specification and recent maintenance and ticket history of the machine concerned.',
                'Published knowledge-article text, and questions asked of the assistant (with personal data removed).',
            ],
            'data_never_sent' => [
                'User names, email addresses, phone numbers or credentials.',
                'Attachments, repair evidence images or internal ticket notes.',
                'Purchase price, supplier or procurement details.',
            ],
        ];
    }
}
