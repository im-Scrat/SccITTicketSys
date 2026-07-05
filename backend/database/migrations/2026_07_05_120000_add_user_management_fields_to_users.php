<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.3 — User Management operational fields (SRS v1.2 DR; SDD v1.2).
 *
 * Additive, backward-compatible migration on the baselined `users` table. No
 * existing column is renamed, retyped, or removed. Three nullable/defaulted
 * columns support administrator-driven lifecycle features:
 *
 *  - force_password_reset : the user must change their password at next sign-in
 *    (FR-USER admin action "Force password reset").
 *  - password_changed_at  : last successful password change (profile display).
 *  - registration_source  : how the account entered the system
 *    (self | admin | seed) — audit/provenance for the directory.
 *
 * Trigram GIN indexes accelerate server-side ILIKE search on the directory
 * (name / employee number). `pg_trgm` is enabled here idempotently; if the
 * extension cannot be created the indexes are skipped gracefully (search still
 * works, just without the trigram acceleration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('force_password_reset')->default(false)->after('password');
            $table->timestampTz('password_changed_at')->nullable()->after('force_password_reset');
            $table->string('registration_source')->nullable()->after('last_login_ip');
        });

        // Provenance backfill: self-registered requests are unambiguous; every
        // other pre-existing row is treated as seeded/administrative.
        DB::table('users')
            ->whereIn('status', ['pending', 'rejected'])
            ->update(['registration_source' => 'self']);
        DB::table('users')
            ->whereNull('registration_source')
            ->update(['registration_source' => 'seed']);

        $this->withTrigramIndexes();
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_first_name_trgm');
        DB::statement('DROP INDEX IF EXISTS users_last_name_trgm');
        DB::statement('DROP INDEX IF EXISTS users_employee_number_trgm');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['force_password_reset', 'password_changed_at', 'registration_source']);
        });
    }

    /**
     * Best-effort trigram acceleration for directory search. Wrapped so a
     * database role without CREATE EXTENSION privilege still migrates cleanly.
     */
    private function withTrigramIndexes(): void
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $e) {
            return; // No trigram support — plain ILIKE still functions.
        }

        DB::statement('CREATE INDEX IF NOT EXISTS users_first_name_trgm ON users USING gin (first_name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS users_last_name_trgm ON users USING gin (last_name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS users_employee_number_trgm ON users USING gin (employee_number gin_trgm_ops)');
    }
};
