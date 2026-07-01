<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use Database\Factories\BackupHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupHistory extends Model
{
    /** @use HasFactory<BackupHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'backup_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'backup_type' => BackupType::class,
            'status' => BackupStatus::class,
            'file_size' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
