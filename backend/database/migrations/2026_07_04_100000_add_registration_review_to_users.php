<?php

use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.2 — registration-request review support (SRS v1.1: OI-02 resolved).
 *
 * Additive migration on the baselined `users` table:
 *  - Widen the `users_status_check` CHECK to the 5-value UserStatus set
 *    (adds `rejected`; SDD DD-18).
 *  - Add rejection audit columns retained for declined requests.
 *
 * `UserStatus` is the single source of truth: the baselined identity migration
 * builds the CHECK from `UserStatus::values()`, so a fresh `migrate` already
 * yields the 5-value constraint. This migration reconciles databases that were
 * migrated under v1.0 (4 values) and adds the review columns on both paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        $allowed = "'".implode("','", UserStatus::values())."'";

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ({$allowed}))");

        Schema::table('users', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('status');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('rejection_reason');
            $table->timestampTz('rejected_at')->nullable()->after('rejected_by');

            $table->index('rejected_by');
            $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropIndex(['rejected_by']);
            $table->dropColumn(['rejection_reason', 'rejected_by', 'rejected_at']);
        });

        // Restore the original v1.0 four-value CHECK.
        $original = "'pending','active','suspended','inactive'";
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ({$original}))");
    }
};
