<?php

declare(strict_types=1);

use App\Enums\ReportContentProfile;
use App\Enums\ReportEmailDeliveryStatus;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportPurpose;
use App\Enums\ReportExportStatus;
use App\Enums\ReportType;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Support\Facades\DB;

/*
 * Phase 2.10 R3 — the report tables' CHECK lists are frozen in their migration
 * as values; these tests keep each one equal to the PHP enum it guards, so a
 * new case cannot be added in one place and refused by the other.
 */

dataset('report checks', [
    'report type' => ['report_exports', 'report_exports_report_type_check', ReportType::values()],
    'format' => ['report_exports', 'report_exports_format_check', ReportExportFormat::values()],
    'status' => ['report_exports', 'report_exports_status_check', ReportExportStatus::values()],
    'profile' => ['report_exports', 'report_exports_profile_check', ReportContentProfile::values()],
    'purpose' => ['report_exports', 'report_exports_purpose_check', ReportExportPurpose::values()],
    'delivery status' => ['report_email_deliveries', 'report_email_deliveries_status_check', ReportEmailDeliveryStatus::values()],
]);

it('keeps the CHECK list equal to the enum', function (string $table, string $constraint, array $values): void {
    $definition = (string) DB::table('pg_constraint')
        ->join('pg_class', 'pg_class.oid', '=', 'pg_constraint.conrelid')
        ->where('pg_class.relname', $table)
        ->where('pg_constraint.conname', $constraint)
        ->value(DB::raw('pg_get_constraintdef(pg_constraint.oid)'));

    preg_match_all("/'([^']+)'/", $definition, $matches);
    $allowed = $matches[1];
    sort($allowed);
    sort($values);

    expect($allowed)->toBe($values);
})->with('report checks');

it('serves every report format through the download-only report profile', function (): void {
    foreach (ReportExportFormat::cases() as $format) {
        expect(AttachmentSecurity::isAllowed($format->mime(), AttachmentSecurity::PROFILE_REPORT))->toBeTrue();
    }
});
