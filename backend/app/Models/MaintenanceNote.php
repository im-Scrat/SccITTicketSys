<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MaintenanceNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $maintenance_record_id
 * @property int|null $technician_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property-read User|null $technician
 */
class MaintenanceNote extends Model
{
    /** @use HasFactory<MaintenanceNoteFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /** @return BelongsTo<User, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
}
