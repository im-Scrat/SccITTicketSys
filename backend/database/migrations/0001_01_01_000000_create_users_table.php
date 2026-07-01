<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Framework auth helper tables (sessions, password reset tokens).
 *
 * NOTE: The application `users` table is NOT created here — it depends on
 * `roles` and carries domain columns (uuid, employee_number, citext email,
 * status, soft deletes, …). It is created in the identity-tables migration
 * (2026_06_30_100010) after `roles` exists. `sessions.user_id` is kept as an
 * index-only column (no FK), matching Laravel's framework convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
    }
};
