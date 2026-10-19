<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Requests;

/**
 * POST /api/admin/reports/print (Phase 2.10, SDD DD-91): the document the print
 * page lays out — the same content a PDF carries. A POST because printing is
 * an audited act, not a read.
 */
class PrintReportRequest extends ReportDocumentRequest {}
