<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiEventType;
use Database\Factories\AiLearningEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLearningEvent extends Model
{
    /** @use HasFactory<AiLearningEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ai_learning_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_type' => AiEventType::class,
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<PcUnit, $this> */
    public function pcUnit(): BelongsTo
    {
        return $this->belongsTo(PcUnit::class);
    }
}
