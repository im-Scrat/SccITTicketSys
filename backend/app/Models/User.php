<?php

declare(strict_types=1);

namespace App\Models;

use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\UserStatus;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $uuid
 * @property int $role_id
 * @property string|null $employee_number
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property string $email
 * @property string $password
 * @property string|null $contact_number
 * @property string|null $profile_picture
 * @property UserStatus $status
 * @property string|null $rejection_reason
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property Carbon|null $email_verified_at
 * @property bool $force_password_reset
 * @property Carbon|null $password_changed_at
 * @property string|null $registration_source
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property Role|null $role
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuidRouteKey, Notifiable, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
            'rejected_at' => 'datetime',
            'force_password_reset' => 'boolean',
            'password_changed_at' => 'datetime',
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isPending(): bool
    {
        return $this->status === UserStatus::Pending;
    }

    /** Whether the user holds the (system) Administrator role. */
    public function isAdministrator(): bool
    {
        return $this->role?->slug === 'administrator';
    }

    /**
     * The user's effective permission slugs (role ∪ grants − denies),
     * resolved and cached by the PermissionResolver (SDD DD-05).
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        return app(PermissionResolver::class)->resolve($this);
    }

    public function hasPermissionTo(string $permission): bool
    {
        return app(PermissionResolver::class)->has($this, $permission);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return BelongsToMany<Permission, $this> */
    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')
            ->withPivot('grant_type', 'granted_by')
            ->withTimestamps();
    }

    /** @return HasMany<LoginHistory, $this> */
    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    /**
     * Activity-log entries where this user is the subject (things done *to* the
     * account), powering the per-user audit timeline (SRS FR-AUD-*).
     *
     * @return MorphMany<ActivityLog, $this>
     */
    public function activityAbout(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject');
    }

    /**
     * The user's in-app notifications (SRS FR-NOT-001; SDD DD-52).
     *
     * **This deliberately overrides the `Notifiable` trait's own
     * `notifications()`.** The framework's version is a morph to
     * `Illuminate\Notifications\DatabaseNotification`, which expects
     * `notifiable_type` / `notifiable_id` columns and a uuid primary key — none
     * of which this project's `notifications` table has. Left in place it would
     * be a loaded gun: the first person to write `$user->notifications` would
     * get an SQL error about a column that has never existed here, and would
     * have no reason to suspect the trait rather than their own code.
     *
     * The trait is still worth having for everything else it provides —
     * `notify()`, `routeNotificationFor()` and the mail routing the Identity
     * notifications have used since Phase 2.2.
     *
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class)->latest('created_at');
    }

    /**
     * Unread notifications — the badge count's relation form.
     *
     * Served by the `notifications_unread` partial index, which exists in the
     * baseline schema for exactly this predicate.
     *
     * @return HasMany<Notification, $this>
     */
    public function unreadNotifications(): HasMany
    {
        return $this->notifications()->whereNull('read_at');
    }

    /** @return HasMany<Ticket, $this> */
    public function reportedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'reporter_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_technician_id');
    }
}
