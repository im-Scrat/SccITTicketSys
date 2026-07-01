<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

/**
 * Seeds the centralized global configuration. `is_protected` marks
 * system-critical keys that must not be deleted; `is_public` marks settings
 * safe to expose to the frontend.
 */
class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // group, key, label, value, type, is_public, is_protected
            ['school', 'school.name', 'School Name', 'Springfield Community College', 'string', true, true],
            ['school', 'school.address', 'School Address', '123 Campus Drive', 'string', true, false],
            ['school', 'school.contact_email', 'Contact Email', 'it@school.test', 'string', true, false],
            ['school', 'school.contact_number', 'Contact Number', '+1-000-000-0000', 'string', true, false],
            ['academic', 'academic.year', 'Academic Year', '2025-2026', 'string', true, true],
            ['academic', 'academic.semester', 'Semester', '1st Semester', 'string', true, false],
            ['branding', 'branding.logo_path', 'Logo Path', null, 'string', true, false],
            ['branding', 'branding.favicon_path', 'Favicon Path', null, 'string', true, false],
            ['theme', 'theme.primary_color', 'Primary Color', '#2563eb', 'string', true, false],
            ['theme', 'theme.mode', 'Theme Mode', 'light', 'string', true, false],
            ['maintenance', 'maintenance.default_interval_days', 'Default Maintenance Interval (days)', 90, 'integer', false, false],
            ['maintenance', 'maintenance.reminder_days', 'Maintenance Reminder (days before)', 7, 'integer', false, false],
            ['qr', 'qr.default_size', 'QR Default Size (px)', 256, 'integer', false, false],
            ['qr', 'qr.error_correction', 'QR Error Correction Level', 'M', 'string', false, false],
            ['floor_plan', 'floor_plan.default_grid_size', 'Floor Plan Grid Size (px)', 20, 'integer', true, false],
            ['floor_plan', 'floor_plan.snap_to_grid', 'Snap To Grid', true, 'boolean', true, false],
            ['notifications', 'notifications.default_channel', 'Default Notification Channel', 'in_app', 'string', false, false],
            ['notifications', 'notifications.digest_enabled', 'Daily Digest Enabled', false, 'boolean', false, false],
            ['ai', 'ai.assistant_enabled', 'AI Assistant Enabled', true, 'boolean', true, false],
            ['system', 'system.timezone', 'System Timezone', 'Asia/Manila', 'string', false, true],
            ['system', 'system.date_format', 'Date Format', 'Y-m-d', 'string', true, false],
        ];

        foreach ($settings as [$group, $key, $label, $value, $type, $isPublic, $isProtected]) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'group' => $group,
                    'label' => $label,
                    'value' => $value,
                    'type' => $type,
                    'is_public' => $isPublic,
                    'is_protected' => $isProtected,
                ],
            );
        }
    }
}
