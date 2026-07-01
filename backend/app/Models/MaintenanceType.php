<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MaintenanceTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceType extends Model
{
    /** @use HasFactory<MaintenanceTypeFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_preventive' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ChecklistTemplate, $this> */
    public function defaultChecklistTemplate(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'default_checklist_template_id');
    }

    /** @return HasMany<MaintenanceRecord, $this> */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }
}
