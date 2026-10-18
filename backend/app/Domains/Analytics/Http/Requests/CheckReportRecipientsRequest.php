<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Requests;

use App\Enums\ReportExportFormat;
use Illuminate\Validation\Rule;

/**
 * POST /api/admin/reports/emails/check-recipients (Phase 2.10, SDD DD-92):
 * normalise the addresses, say which are outside the school, and describe
 * exactly what would be sent — before anything is.
 *
 * Recipients arrive as a list or as one text (comma, semicolon or newline
 * separated); they are trimmed, lower-cased and de-duplicated here, without
 * touching the database (this runs before authorization).
 */
class CheckReportRecipientsRequest extends ReportDocumentRequest
{
    /** Formats an email may carry: identifier-only documents, no CSV. */
    public const EMAIL_FORMATS = [ReportExportFormat::Pdf, ReportExportFormat::Docx, ReportExportFormat::Xlsx];

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $raw = $this->input('recipients');
        $items = is_array($raw) ? $raw : (is_string($raw) ? preg_split('/[\s,;]+/', $raw) : []);
        $clean = [];

        foreach ($items ?: [] as $item) {
            if (is_string($item) && trim($item) !== '') {
                $clean[] = strtolower(trim($item));
            }
        }

        $this->merge(['recipients' => array_values(array_unique($clean))]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function documentRules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in(array_map(static fn (ReportExportFormat $f): string => $f->value, self::EMAIL_FORMATS))],
            'recipients' => ['required', 'array', 'min:1', 'max:'.(int) config('reports.email.max_recipients', 10)],
            'recipients.*' => ['required', 'string', 'max:254', 'email:rfc,strict,spoof', 'distinct'],
        ];
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        /** @var list<string> */
        return array_values((array) $this->validated('recipients'));
    }

    public function exportFormat(): ReportExportFormat
    {
        return ReportExportFormat::from((string) $this->validated('format'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipients.max' => 'Send to at most :max addresses at a time.',
            'recipients.*.email' => '":input" is not a valid email address.',
        ];
    }
}
