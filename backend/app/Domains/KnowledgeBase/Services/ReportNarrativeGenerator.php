<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\Analytics\DTOs\OverallReportFilters;
use App\Domains\Analytics\DTOs\ReportEvidencePacket;
use App\Domains\Analytics\Services\ReportEvidencePacketBuilder;
use App\Domains\KnowledgeBase\Agents\ReportNarrativeAgent;
use App\Domains\KnowledgeBase\Jobs\GenerateReportNarrativeJob;
use App\Enums\ReportNarrativeStatus;
use App\Models\ReportNarrative;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * AI interpretations of the overall report: on demand, cached against exactly
 * what they interpreted (owner decision D4; Phase 2.10 R5; SDD DD-95).
 *
 * - **Gated before any call.** The facts are computed first; if no question is
 *   sufficient, an `insufficient` row records that, and no provider is asked.
 * - **Cached by content.** The cache key is the report, the resolved filters,
 *   the facts' sha256, the prompt version and the model. Asking again for the
 *   same data returns the same row; the same filters on changed data is a new
 *   interpretation, and the old one is reported as stale, with the facts that
 *   changed.
 * - **Off unless switched on** (`ai.report_narratives_enabled`), and
 *   unavailable without a configured model and key — both said plainly.
 */
final class ReportNarrativeGenerator
{
    public const REPORT_TYPE = 'overall';

    public function __construct(
        private readonly ReportEvidencePacketBuilder $packets,
        private readonly AiSettings $settings,
    ) {}

    public function packet(OverallReportFilters $filters): ReportEvidencePacket
    {
        return $this->packets->build($filters);
    }

    public function filtersHash(OverallReportFilters $filters): string
    {
        return sha1(json_encode($filters->cacheKeyParts(), JSON_THROW_ON_ERROR));
    }

    /** Whether the switch is on and a model with a key is configured. */
    public function configured(): bool
    {
        try {
            $model = $this->settings->activeModel();

            return $this->settings->hasProviderKey($this->settings->providerFor($model));
        } catch (\Throwable) {
            return false;
        }
    }

    public function modelIdentifier(): ?string
    {
        try {
            return $this->settings->modelIdentifierFor($this->settings->activeModel());
        } catch (\Throwable) {
            return null;
        }
    }

    public function cacheKey(OverallReportFilters $filters, ReportEvidencePacket $packet, ?string $model): string
    {
        return hash('sha256', implode('|', [self::REPORT_TYPE, $this->filtersHash($filters), $packet->factsHash(), ReportNarrativeAgent::PROMPT_VERSION, $model ?? 'none']));
    }

    /**
     * The interpretation for exactly this data, if one was completed.
     */
    public function completedFor(OverallReportFilters $filters, ?ReportEvidencePacket $packet = null): ?ReportNarrative
    {
        $packet ??= $this->packet($filters);

        return ReportNarrative::query()
            ->where('cache_key', $this->cacheKey($filters, $packet, $this->modelIdentifier()))
            ->where('status', ReportNarrativeStatus::Completed->value)
            ->first();
    }

    /**
     * What the screen shows: the facts (always), the latest interpretation for
     * these filters, and whether it still matches the data.
     *
     * @return array{packet: ReportEvidencePacket, narrative: ReportNarrative|null, current: bool, changed_fact_keys: list<string>, enabled: bool, configured: bool}
     */
    public function state(OverallReportFilters $filters): array
    {
        $packet = $this->packet($filters);
        $latest = ReportNarrative::query()
            ->where('report_type', self::REPORT_TYPE)
            ->where('filters_hash', $this->filtersHash($filters))
            ->latest('id')
            ->first();

        $current = $latest !== null && $latest->cache_key === $this->cacheKey($filters, $packet, $this->modelIdentifier());
        $changed = $latest !== null && ! $current
            ? $packet->changedFrom(ReportEvidencePacket::fromArray($latest->evidence))
            : [];

        return [
            'packet' => $packet,
            'narrative' => $latest,
            'current' => $current,
            'changed_fact_keys' => $changed,
            'enabled' => $this->settings->reportNarrativesEnabled(),
            'configured' => $this->configured(),
        ];
    }

    /**
     * Ask for an interpretation of exactly this data.
     */
    public function request(OverallReportFilters $filters, User $actor): ReportNarrative
    {
        $packet = $this->packet($filters);
        $model = $this->modelIdentifier();
        $key = $this->cacheKey($filters, $packet, $model);

        $existing = ReportNarrative::query()->where('cache_key', $key)->first();
        if ($existing !== null && in_array($existing->status, [ReportNarrativeStatus::Pending, ReportNarrativeStatus::Completed, ReportNarrativeStatus::Insufficient], true)) {
            return $existing;
        }

        $status = match (true) {
            ! $this->settings->reportNarrativesEnabled() => ReportNarrativeStatus::Disabled,
            ! $packet->anySufficient() => ReportNarrativeStatus::Insufficient,
            ! $this->configured() => ReportNarrativeStatus::Unavailable,
            default => ReportNarrativeStatus::Pending,
        };

        $attributes = [
            'report_type' => self::REPORT_TYPE,
            'filters_hash' => $this->filtersHash($filters),
            'facts_hash' => $packet->factsHash(),
            'prompt_version' => ReportNarrativeAgent::PROMPT_VERSION,
            'model' => $model,
            'requested_by' => $actor->getKey(),
            'filters' => $filters->toQuery(),
            'status' => $status,
            'evidence' => $packet->toArray(),
            'error_code' => null,
        ];

        try {
            $narrative = $existing !== null
                ? tap($existing)->forceFill($attributes)->save()
                : ReportNarrative::query()->create(['cache_key' => $key, ...$attributes]);
        } catch (UniqueConstraintViolationException) {
            return ReportNarrative::query()->where('cache_key', $key)->firstOrFail();
        }

        if ($status === ReportNarrativeStatus::Pending) {
            GenerateReportNarrativeJob::dispatch($narrative->id)->afterCommit();
        }

        return $narrative;
    }
}
