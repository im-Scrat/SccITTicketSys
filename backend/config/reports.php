<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reports: documents, email and retention (Phase 2.10, SDD DD-85/DD-91/DD-92)
|--------------------------------------------------------------------------
|
| Every value has a default, so `.env.production` needs no edit unless the
| school changes one. The document budgets are measurements, not guesses: a
| 600-row PDF peaked at 192 MB on the production image and 2,000 rows
| exhausted 512 MB (DD-85), and a document that runs out of memory fails in a
| queue worker, out of the administrator's sight.
|
*/

return [

    // Documents and emails carry "SccIT" (owner decision, 2026-10-18).
    'organisation' => env('REPORT_ORGANISATION', 'SccIT'),

    'exports' => [
        // Files are deleted after this; the row stays, marked expired.
        'retention_hours' => (int) env('REPORT_EXPORT_RETENTION_HOURS', 72),

        // Rows across every table of a PDF or Word document.
        'paged_row_budget' => (int) env('REPORT_PAGED_ROW_BUDGET', 600),

        // Cells across every sheet of an Excel workbook.
        'xlsx_cell_budget' => (int) env('REPORT_XLSX_CELL_BUDGET', 150000),

        // The most records any document fetches at all.
        'record_limit' => (int) env('REPORT_RECORD_LIMIT', 10000),

        // Request IPs and user agents are nulled, and delivery rows deleted, after this.
        'row_retention_days' => (int) env('REPORT_ROW_RETENTION_DAYS', 180),

        // The queue documents render on (the dedicated bulk worker, SDD DD-93).
        'queue' => env('REPORT_EXPORT_QUEUE', 'bulk'),
    ],

    'email' => [
        'max_recipients' => 10,

        // Recipients per administrator per school day, across all requests.
        'daily_cap' => (int) env('REPORT_EMAIL_DAILY_CAP', 50),

        // Addresses at these domains are "inside the school"; every other
        // address needs the extra confirmation. Comma-separated.
        'internal_domains' => array_values(array_filter(array_map(
            static fn (string $d): string => strtolower(trim($d)),
            explode(',', (string) env('REPORT_EMAIL_INTERNAL_DOMAINS', '')),
        ))),

        // False while outgoing mail goes to a capture sink (Mailpit) rather
        // than real mailboxes; the dialog then says so before anything is sent.
        'delivers_externally' => (bool) env('REPORT_EMAIL_DELIVERS_EXTERNALLY', false),

        // Deliveries still queued after this many hours are marked failed.
        'stale_after_hours' => 24,
    ],
];
