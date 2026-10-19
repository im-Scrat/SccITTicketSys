<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Requests;

use App\Domains\Analytics\Services\ReportEmailer;
use Illuminate\Validation\Validator;

/**
 * POST /api/admin/reports/emails (Phase 2.10, owner decision D1, SDD DD-92).
 *
 * Any address may receive a report, with safeguards. The one this request
 * owns: **`confirmed_external` must equal the server's own list of outside
 * addresses.** The dialog shows that list and asks for a tick; the server
 * re-derives it and refuses unless the two match exactly, so an address added
 * after the confirmation (or by a script that never showed it) cannot slip
 * through.
 */
class StoreReportEmailRequest extends CheckReportRecipientsRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $raw = $this->input('confirmed_external');
        $items = is_array($raw) ? $raw : [];
        $this->merge(['confirmed_external' => array_values(array_unique(array_map(
            static fn (mixed $v): string => is_string($v) ? strtolower(trim($v)) : '',
            $items,
        )))]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function documentRules(): array
    {
        return [
            ...parent::documentRules(),
            'confirmed_external' => ['present', 'array', 'max:'.(int) config('reports.email.max_recipients', 10)],
            'confirmed_external.*' => ['string'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['recipients', 'recipients.*'])) {
                    return;
                }

                $expected = app(ReportEmailer::class)->externalOf((array) $this->input('recipients'));
                $confirmed = (array) $this->input('confirmed_external');
                sort($expected);
                sort($confirmed);

                if ($expected !== $confirmed) {
                    $validator->errors()->add(
                        'confirmed_external',
                        'Confirm every address outside the school before sending. The list of outside addresses has changed — check it again.',
                    );
                }
            },
        ];
    }
}
