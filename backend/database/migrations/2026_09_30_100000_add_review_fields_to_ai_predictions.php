<?php

use App\Enums\PredictionRiskLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-L — give an AI prediction the fields its own requirement asks it to keep
 * apart (SRS FR-AI-011; the WP-L/WP-M mandate: observed fact, detected pattern,
 * predicted risk, recommendation, confidence).
 *
 * ── Why the baseline table is not enough ───────────────────────────────────
 *
 * `ai_predictions` was baselined with `predicted_issue`, `probability`,
 * `predicted_within_days` and one free-text `explanation`. That shape can only
 * hold the five parts by running them together in prose, which is precisely
 * what the mandate forbids, and it has no column for three of them:
 *
 * - **confidence** is not `probability`. Probability is a claim about the
 *   machine; confidence is the model's own judgement of how well the evidence
 *   supports its reading. Storing one in the other's column would misstate
 *   both. Probability stays in the table and stays null for AI-generated rows
 *   (see `PredictionRiskLevel`).
 * - **risk_level** carries the severity judgement as a category, the honest
 *   form when no calibrated model exists to produce a number.
 * - **recommendation** is the preventive action, kept out of the explanation so
 *   a reviewer can act on it without parsing prose.
 * - **evidence** holds the observed facts and the pattern exactly as computed
 *   from `maintenance_records` — sample size, dates, intervals — so what the
 *   model was shown is reviewable next to what it concluded.
 * - **ai_failure_pattern_id** ties the prediction to the deterministic pattern
 *   that justified asking for one at all.
 *
 * **uuid**: predictions become addressable on the Admin review surface (WP-M),
 * and every publicly addressable table here carries one (DD-04); a bigint key
 * would be enumerable.
 *
 * Purely additive, nullable, no existing column retyped or removed. The
 * backfill-then-NOT-NULL sequence for `uuid` is the one
 * `2026_08_29_100000_add_uuid_to_qr_scan_logs` established.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_predictions', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
            $table->unsignedBigInteger('ai_failure_pattern_id')->nullable()->after('ai_model_id');
            $table->string('risk_level', 16)->nullable()->after('predicted_issue');
            $table->decimal('confidence', 5, 4)->nullable()->after('probability');
            $table->text('recommendation')->nullable()->after('explanation');
            $table->jsonb('evidence')->nullable()->after('recommendation');
        });

        DB::statement('UPDATE ai_predictions SET uuid = gen_random_uuid() WHERE uuid IS NULL');
        DB::statement('ALTER TABLE ai_predictions ALTER COLUMN uuid SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE ai_predictions ALTER COLUMN uuid SET NOT NULL');

        $levels = implode(', ', array_map(
            static fn (string $value): string => "'{$value}'",
            PredictionRiskLevel::values(),
        ));
        DB::statement("ALTER TABLE ai_predictions ADD CONSTRAINT ai_predictions_risk_level_check CHECK (risk_level IS NULL OR risk_level IN ({$levels}))");
        DB::statement('ALTER TABLE ai_predictions ADD CONSTRAINT ai_predictions_confidence_check CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1)');

        Schema::table('ai_predictions', function (Blueprint $table): void {
            $table->unique('uuid', 'ai_predictions_uuid_unique');
            $table->index('ai_failure_pattern_id', 'ai_predictions_ai_failure_pattern_id_index');
            // A pattern is recomputed on every run and may be removed; the
            // prediction outlives it as history, so the link clears rather than
            // the prediction disappearing with it.
            $table->foreign('ai_failure_pattern_id', 'ai_predictions_ai_failure_pattern_id_foreign')
                ->references('id')->on('ai_failure_patterns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_predictions', function (Blueprint $table): void {
            $table->dropForeign('ai_predictions_ai_failure_pattern_id_foreign');
            $table->dropIndex('ai_predictions_ai_failure_pattern_id_index');
            $table->dropUnique('ai_predictions_uuid_unique');
        });

        DB::statement('ALTER TABLE ai_predictions DROP CONSTRAINT IF EXISTS ai_predictions_confidence_check');
        DB::statement('ALTER TABLE ai_predictions DROP CONSTRAINT IF EXISTS ai_predictions_risk_level_check');

        Schema::table('ai_predictions', function (Blueprint $table): void {
            $table->dropColumn(['uuid', 'ai_failure_pattern_id', 'risk_level', 'confidence', 'recommendation', 'evidence']);
        });
    }
};
