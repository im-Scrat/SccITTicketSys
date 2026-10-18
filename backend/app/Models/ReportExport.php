<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportContentProfile;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportPurpose;
use App\Enums\ReportExportStatus;
use App\Enums\ReportType;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\ReportExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One requested report document (Phase 2.10, SDD DD-91).
 *
 * `filters` holds the validated report parameters and `as_of` the instant the
 * request reported up to; together they rebuild exactly the report the
 * administrator was looking at, however long the job waits in the queue.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property ReportType $report_type
 * @property ReportExportFormat $format
 * @property ReportExportStatus $status
 * @property ReportContentProfile $profile
 * @property ReportExportPurpose $purpose
 * @property array<string, mixed> $filters
 * @property array<string, mixed> $options
 * @property int|null $narrative_id
 * @property Carbon $as_of
 * @property string $dedupe_key
 * @property string $disk
 * @property string|null $path
 * @property string|null $filename
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property int|null $row_count
 * @property bool $truncated
 * @property string|null $error_code
 * @property int $attempts
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property int $download_count
 * @property Carbon|null $last_downloaded_at
 * @property string|null $request_ip
 * @property string|null $request_user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReportExport extends Model
{
    /** @use HasFactory<ReportExportFactory> */
    use HasFactory, HasUuidRouteKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'format' => ReportExportFormat::class,
            'status' => ReportExportStatus::class,
            'profile' => ReportContentProfile::class,
            'purpose' => ReportExportPurpose::class,
            'filters' => 'array',
            'options' => 'array',
            'as_of' => 'immutable_datetime',
            'truncated' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ReportEmailDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportEmailDelivery::class);
    }

    /** The file exists and may still be downloaded. */
    public function isDownloadable(): bool
    {
        return $this->status === ReportExportStatus::Completed
            && $this->path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
