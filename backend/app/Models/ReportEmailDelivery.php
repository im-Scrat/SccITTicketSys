<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportEmailDeliveryStatus;
use App\Support\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recipient's copy of an emailed report (Phase 2.10, SDD DD-92).
 *
 * @property int $id
 * @property string $uuid
 * @property int $report_export_id
 * @property int|null $requested_by
 * @property string $recipient
 * @property bool $is_external
 * @property ReportEmailDeliveryStatus $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReportEmailDelivery extends Model
{
    use HasUuidRouteKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ReportEmailDeliveryStatus::class,
            'is_external' => 'boolean',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReportExport, $this> */
    public function export(): BelongsTo
    {
        return $this->belongsTo(ReportExport::class, 'report_export_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
