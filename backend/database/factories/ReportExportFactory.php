<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportContentProfile;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportPurpose;
use App\Enums\ReportExportStatus;
use App\Enums\ReportType;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportExport>
 */
class ReportExportFactory extends Factory
{
    protected $model = ReportExport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'report_type' => ReportType::TicketOverview,
            'format' => ReportExportFormat::Xlsx,
            'status' => ReportExportStatus::Pending,
            'profile' => ReportContentProfile::Full,
            'purpose' => ReportExportPurpose::Download,
            'filters' => ['period' => 'month'],
            'options' => ['include_records' => false, 'orientation' => 'portrait', 'paper' => 'a4'],
            'as_of' => now()->startOfMinute()->addMinute(),
            'dedupe_key' => hash('sha256', (string) fake()->uuid()),
        ];
    }

    public function completed(string $path = 'report-exports/test/report.xlsx'): static
    {
        return $this->state(fn (): array => [
            'status' => ReportExportStatus::Completed,
            'path' => $path,
            'filename' => basename($path),
            'size_bytes' => 10,
            'sha256' => str_repeat('a', 64),
            'completed_at' => now(),
            'expires_at' => now()->addHours(72),
        ]);
    }
}
