<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.10 R5 — which AI interpretation, if any, a report document carried
 * (SDD DD-95). Kept out of the R3 migration on purpose: a foreign key to a
 * table a later release creates would fail on a stack that deployed R3 first.
 * Nullable, and nulled if the interpretation is ever pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->foreignId('narrative_id')->nullable()->after('options')
                ->constrained('report_narratives')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('report_exports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('narrative_id');
        });
    }
};
