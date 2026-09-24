<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MaintenanceChecklistFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $maintenance_record_id
 * @property int|null $checklist_template_item_id
 * @property string $item_label
 * @property bool $is_required
 * @property int $sort_order
 * @property bool $is_completed
 * @property string|null $remarks
 * @property int|null $completed_by
 * @property Carbon|null $completed_at
 * @property-read User|null $completedBy
 * @property-read ChecklistTemplateItem|null $templateItem
 */
class MaintenanceChecklist extends Model
{
    /** @use HasFactory<MaintenanceChecklistFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            // Copied from the template item at instantiation so the
            // completion gate survives the template being edited or
            // deleted afterwards (FR-MNT-004).
            'is_required' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /** @return BelongsTo<ChecklistTemplateItem, $this> */
    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplateItem::class, 'checklist_template_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
