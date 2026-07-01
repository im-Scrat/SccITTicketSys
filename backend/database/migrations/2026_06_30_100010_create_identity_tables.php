<?php

use App\Enums\PermissionGrantType;
use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identity & access control: roles, permissions, RBAC pivots, users.
 * Foreign keys are added centrally in the add_foreign_keys migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('module');
            $table->text('description')->nullable();
            $table->timestampsTz();

            $table->index('module');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('role_id');

            $table->string('employee_number')->nullable()->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');

            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            $table->string('contact_number')->nullable();
            $table->string('profile_picture')->nullable();

            $table->enum('status', UserStatus::values())->default(UserStatus::Active->value);
            $table->timestampTz('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('role_id');
            $table->index('status');
            $table->index('created_by');
            $table->index('updated_by');
        });

        // Case-insensitive email (rebuilds the unique index as citext).
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestampsTz();

            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('permission_id');
            $table->enum('grant_type', PermissionGrantType::values())->default(PermissionGrantType::Grant->value);
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestampsTz();

            $table->primary(['user_id', 'permission_id']);
            $table->index('permission_id');
            $table->index('granted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
