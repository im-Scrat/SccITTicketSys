<?php

declare(strict_types=1);

namespace App\Domains\Assets\DTOs;

use App\Enums\ImportIssueSeverity;

/**
 * One problem with one imported row, from the single issue catalogue
 * (Phase 2.10 R6, plan Appendix B.5).
 *
 * Every issue has a code from {@see CATALOGUE}, which fixes its severity and
 * the action that clears it; the message is plain language for the
 * administrator, never a validator key. `scope` records which pass raised it —
 * the row's own checks (`row`), its location or catalog decision (`link`), or
 * a comparison with the other rows of the file (`file`) — so each pass can
 * replace its own issues without disturbing the others'.
 */
final readonly class ImportIssue
{
    public const SCOPE_ROW = 'row';

    public const SCOPE_LINK = 'link';

    public const SCOPE_FILE = 'file';

    /**
     * code => [severity, action]. The action names what clears it:
     * `correct` a value, `acknowledge` it, decide the row's location or
     * catalog entry, choose a reference, or `exclude` the row.
     *
     * @var array<string, array{0: ImportIssueSeverity, 1: string}>
     */
    public const CATALOGUE = [
        'required_missing' => [ImportIssueSeverity::Error, 'correct'],
        'invalid_format' => [ImportIssueSeverity::Error, 'correct'],
        'too_long' => [ImportIssueSeverity::Error, 'correct'],
        'unknown_value' => [ImportIssueSeverity::Error, 'correct'],
        'date_unparseable' => [ImportIssueSeverity::Error, 'correct'],
        'date_ambiguous' => [ImportIssueSeverity::Error, 'correct'],
        'number_ambiguous' => [ImportIssueSeverity::Error, 'correct'],
        'warranty_before_purchase' => [ImportIssueSeverity::Error, 'correct'],
        'duplicate_in_file' => [ImportIssueSeverity::Error, 'correct'],
        'type_unmapped' => [ImportIssueSeverity::Error, 'correct'],
        'catalog_type_missing' => [ImportIssueSeverity::Error, 'correct'],
        'catalog_rejected' => [ImportIssueSeverity::Error, 'exclude'],
        'custodian_not_email' => [ImportIssueSeverity::Error, 'correct'],

        'duplicate_existing' => [ImportIssueSeverity::Duplicate, 'exclude'],
        'duplicate_archived' => [ImportIssueSeverity::Duplicate, 'exclude'],
        'repeat_in_file' => [ImportIssueSeverity::Duplicate, 'exclude'],

        'possible_duplicate_serial' => [ImportIssueSeverity::Review, 'acknowledge'],
        'cross_table_identifier' => [ImportIssueSeverity::Review, 'acknowledge'],
        'location_unresolved' => [ImportIssueSeverity::Review, 'decide_location'],
        'location_blank' => [ImportIssueSeverity::Review, 'decide_location'],
        'location_inactive' => [ImportIssueSeverity::Review, 'decide_location'],
        'catalog_pending' => [ImportIssueSeverity::Review, 'decide_catalog'],
        'catalog_ambiguous' => [ImportIssueSeverity::Review, 'decide_catalog'],
        'supplier_unmatched' => [ImportIssueSeverity::Review, 'acknowledge'],
        'custodian_unmatched' => [ImportIssueSeverity::Review, 'acknowledge'],
        'quantity_gt_one' => [ImportIssueSeverity::Review, 'acknowledge'],
        'date_from_serial' => [ImportIssueSeverity::Review, 'acknowledge'],

        'number_as_identifier' => [ImportIssueSeverity::Warning, 'none'],
        'formula_without_value' => [ImportIssueSeverity::Warning, 'none'],
        'formula_like_text' => [ImportIssueSeverity::Warning, 'none'],
        'cell_error' => [ImportIssueSeverity::Warning, 'none'],
        'terminal_status' => [ImportIssueSeverity::Warning, 'none'],
        'supplier_inactive' => [ImportIssueSeverity::Warning, 'none'],

        'merged_fill' => [ImportIssueSeverity::Info, 'none'],
        'mac_reformatted' => [ImportIssueSeverity::Info, 'none'],
        'value_unwrapped' => [ImportIssueSeverity::Info, 'none'],
    ];

    /**
     * @param  array<string, mixed>  $related
     */
    public function __construct(
        public string $code,
        public ?string $field,
        public string $message,
        public string $scope = self::SCOPE_ROW,
        public array $related = [],
    ) {
        if (! isset(self::CATALOGUE[$code])) {
            throw new \InvalidArgumentException("Unknown import issue [{$code}].");
        }
    }

    /**
     * @param  array<string, mixed>  $related
     */
    public static function make(string $code, ?string $field, string $message, array $related = [], string $scope = self::SCOPE_ROW): self
    {
        return new self($code, $field, $message, $scope, $related);
    }

    public function severity(): ImportIssueSeverity
    {
        return self::CATALOGUE[$this->code][0];
    }

    public function action(): string
    {
        return self::CATALOGUE[$this->code][1];
    }

    /** A review issue cleared by an acknowledgement rather than a decision. */
    public static function isAcknowledgeable(string $code): bool
    {
        return (self::CATALOGUE[$code][1] ?? null) === 'acknowledge';
    }

    /**
     * @return array{code: string, severity: string, field: string|null, message: string, action: string, scope: string, related: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity()->value,
            'field' => $this->field,
            'message' => $this->message,
            'action' => $this->action(),
            'scope' => $this->scope,
            'related' => $this->related,
        ];
    }
}
