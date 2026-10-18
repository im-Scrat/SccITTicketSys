<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportNarrativeStatus;
use App\Support\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An AI interpretation of the overall report (Phase 2.10 R5, SDD DD-95).
 *
 * @property int $id
 * @property string $uuid
 * @property string $report_type
 * @property string $cache_key
 * @property string $filters_hash
 * @property string $facts_hash
 * @property string $prompt_version
 * @property string|null $model
 * @property int|null $requested_by
 * @property array<string, mixed> $filters
 * @property ReportNarrativeStatus $status
 * @property array<string, mixed> $evidence
 * @property array<string, string>|null $coverage
 * @property list<array<string, mixed>>|null $findings
 * @property list<string>|null $limitations
 * @property int $withheld_count
 * @property string|null $error_code
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int|null $latency_ms
 * @property Carbon|null $generated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReportNarrative extends Model
{
    use HasUuidRouteKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ReportNarrativeStatus::class,
            'filters' => 'array',
            'evidence' => 'array',
            'coverage' => 'array',
            'findings' => 'array',
            'limitations' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
