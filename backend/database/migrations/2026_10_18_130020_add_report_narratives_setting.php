<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.10 R5 — the switch for AI interpretations of reports
 * (`system_settings.ai.report_narratives_enabled`; plan decision 9.1-4).
 *
 * **Off**, and only inserted if absent: an administrator turns it on after
 * reading what is sent to the provider on the AI settings page. The seeder
 * carries the same row for a fresh install. `down()` removes the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('system_settings')->where('key', 'ai.report_narratives_enabled')->exists()) {
            return;
        }

        DB::table('system_settings')->insert([
            'group' => 'ai',
            'key' => 'ai.report_narratives_enabled',
            'label' => 'AI Interpretations of Reports',
            'value' => json_encode(false),
            'type' => 'boolean',
            'description' => 'Lets administrators ask the AI provider to interpret aggregated report figures (codes and counts only).',
            'is_public' => false,
            'is_protected' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'ai.report_narratives_enabled')->delete();
    }
};
