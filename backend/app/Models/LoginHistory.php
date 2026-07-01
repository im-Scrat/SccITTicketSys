<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginStatus;
use Database\Factories\LoginHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginHistory extends Model
{
    /** @use HasFactory<LoginHistoryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'login_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'login_status' => LoginStatus::class,
            'login_at' => 'datetime',
            'logout_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
