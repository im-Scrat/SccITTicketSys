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
