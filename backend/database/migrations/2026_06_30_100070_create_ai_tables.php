<?php

use App\Enums\AiEventType;
use App\Enums\AiModality;
use App\Enums\AiSender;
use App\Enums\AiSeverity;
use App\Enums\EmbeddableSourceType;
use App\Enums\EmbeddingStatus;
use App\Enums\KnowledgeStatus;
use App\Enums\PredictionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI / RAG layer (Gemini + Retrieval-Augmented Generation, pgvector).
 * The embedding vector column + HNSW index are added here / in advanced objects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('provider');
            $table->string('model_identifier');
            $table->string('version')->nullable();
            $table->enum('modality', AiModality::values())->default(AiModality::Text->value);
            $table->integer('embedding_dimensions')->nullable();
            $table->jsonb('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
        });

        Schema::create('ai_analysis_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('ai_model_id')->nullable();
            $table->timestampTz('analyzed_at')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('problem_category')->nullable();
            $table->enum('severity', AiSeverity::values())->nullable();
            $table->integer('estimated_resolution_minutes')->nullable();
            $table->boolean('technician_required')->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('raw_response')->nullable();
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['ticket_id', 'created_at']);
            $table->index('ai_model_id');
        });
        DB::statement('ALTER TABLE ai_analysis_logs ADD CONSTRAINT ai_analysis_logs_confidence_check CHECK (confidence_score IS NULL OR confidence_score BETWEEN 0 AND 1)');

        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ai_analysis_log_id');
            $table->integer('step_order')->default(1);
            $table->text('recommendation');
            $table->boolean('is_completed')->default(false);
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('ai_analysis_log_id');
            $table->index('completed_by');
        });

        Schema::create('ai_conversation_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('conversation_id');
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('ai_model_id')->nullable();
            $table->enum('sender', AiSender::values());
            $table->text('message');
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['conversation_id', 'created_at']);
            $table->index('ticket_id');
            $table->index('user_id');
            $table->index('ai_model_id');
        });

        Schema::create('ai_learning_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_record_id')->nullable();
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->unsignedBigInteger('pc_unit_id')->nullable();
            $table->enum('event_type', AiEventType::values());
            $table->text('event_summary')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('maintenance_record_id');
            $table->index('ticket_id');
            $table->index('pc_unit_id');
            $table->index('event_type');
        });

        Schema::create('ai_failure_patterns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pc_unit_id')->nullable();
            $table->unsignedBigInteger('hardware_component_id')->nullable();
            $table->string('pattern_name');
            $table->string('detected_problem')->nullable();
            $table->integer('occurrence_count')->default(0);
            $table->integer('average_days_between_failures')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestampTz('last_detected')->nullable();
            $table->timestampsTz();

            $table->index(['pc_unit_id', 'last_detected']);
            $table->index('hardware_component_id');
        });
        DB::statement('ALTER TABLE ai_failure_patterns ADD CONSTRAINT ai_failure_patterns_confidence_check CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1)');
        DB::statement('ALTER TABLE ai_failure_patterns ADD CONSTRAINT ai_failure_patterns_occurrence_check CHECK (occurrence_count >= 0)');

        Schema::create('ai_knowledge_articles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('category')->nullable();
            $table->text('problem_signature')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('verified_solution')->nullable();
            $table->integer('verification_count')->default(0);
            $table->enum('status', KnowledgeStatus::values())->default(KnowledgeStatus::Draft->value);
            $table->unsignedBigInteger('created_from_ticket_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('created_from_ticket_id');
            $table->index('created_by');
            $table->index('status');
            $table->index('category');
        });

        Schema::create('ai_predictions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pc_unit_id');
            $table->unsignedBigInteger('ai_model_id')->nullable();
            $table->string('predicted_issue');
            $table->decimal('probability', 5, 4)->nullable();
            $table->integer('predicted_within_days')->nullable();
            $table->text('explanation')->nullable();
            $table->enum('status', PredictionStatus::values())->default(PredictionStatus::Pending->value);
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['pc_unit_id', 'generated_at']);
            $table->index('ai_model_id');
            $table->index('status');
        });
        DB::statement('ALTER TABLE ai_predictions ADD CONSTRAINT ai_predictions_probability_check CHECK (probability IS NULL OR probability BETWEEN 0 AND 1)');

        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ai_recommendation_id')->nullable();
            $table->unsignedBigInteger('ai_analysis_log_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->boolean('was_helpful')->nullable();
            $table->integer('rating')->nullable();
            $table->text('feedback')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['ai_recommendation_id', 'user_id']);
            $table->index('ai_analysis_log_id');
            $table->index('user_id');
        });
        DB::statement('ALTER TABLE ai_feedback ADD CONSTRAINT ai_feedback_rating_check CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)');

        Schema::create('ai_embeddings', function (Blueprint $table) {
            $table->id();
            $table->enum('embeddable_type', EmbeddableSourceType::values());
            $table->unsignedBigInteger('embeddable_id');
            $table->unsignedBigInteger('ai_model_id');
            $table->integer('chunk_index')->default(0);
            $table->text('content');
            $table->string('content_hash');
            $table->integer('token_count')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['embeddable_type', 'embeddable_id']);
            $table->index('ai_model_id');
            $table->unique(['embeddable_type', 'embeddable_id', 'chunk_index', 'ai_model_id'], 'ai_embeddings_source_chunk_model_unique');
        });
        // pgvector column (no Blueprint type). Dimension 768 = Gemini text-embedding-004.
        DB::statement('ALTER TABLE ai_embeddings ADD COLUMN embedding vector(768) NOT NULL');

        Schema::create('ai_embedding_sources', function (Blueprint $table) {
            $table->id();
            $table->enum('source_type', EmbeddableSourceType::values());
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('ai_model_id');
            $table->enum('embedding_status', EmbeddingStatus::values())->default(EmbeddingStatus::Pending->value);
            $table->integer('chunk_count')->default(0);
            $table->string('content_hash')->nullable();
            $table->timestampTz('indexed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->unique(['source_type', 'source_id', 'ai_model_id'], 'ai_embedding_sources_source_model_unique');
            $table->index('ai_model_id');
            $table->index('embedding_status');
        });

        Schema::create('ai_system_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('active_model_id')->nullable();
            $table->unsignedBigInteger('embedding_model_id')->nullable();
            $table->decimal('confidence_threshold', 5, 4)->nullable();
            $table->boolean('enable_predictions')->default(false);
            $table->boolean('enable_learning')->default(false);
            $table->boolean('auto_generate_articles')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();

            $table->index('active_model_id');
            $table->index('embedding_model_id');
            $table->index('updated_by');
        });
        DB::statement('ALTER TABLE ai_system_settings ADD CONSTRAINT ai_system_settings_threshold_check CHECK (confidence_threshold IS NULL OR confidence_threshold BETWEEN 0 AND 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_system_settings');
        Schema::dropIfExists('ai_embedding_sources');
        Schema::dropIfExists('ai_embeddings');
        Schema::dropIfExists('ai_feedback');
        Schema::dropIfExists('ai_predictions');
        Schema::dropIfExists('ai_knowledge_articles');
        Schema::dropIfExists('ai_failure_patterns');
        Schema::dropIfExists('ai_learning_events');
        Schema::dropIfExists('ai_conversation_logs');
        Schema::dropIfExists('ai_recommendations');
        Schema::dropIfExists('ai_analysis_logs');
        Schema::dropIfExists('ai_models');
    }
};
