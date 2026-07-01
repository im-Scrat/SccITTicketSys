<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScanResult;
use App\Models\QrCode;
use App\Models\QrScanLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QrScanLog>
 */
class QrScanLogFactory extends Factory
{
    protected $model = QrScanLog::class;

    public function definition(): array
    {
        return [
            'qr_code_id' => QrCode::factory(),
            'scanned_by' => User::factory(),
            'scan_result' => ScanResult::Success->value,
            'ip_address' => fake()->ipv4(),
            'scanned_at' => now(),
        ];
    }
}
