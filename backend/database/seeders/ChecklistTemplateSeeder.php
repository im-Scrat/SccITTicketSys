<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\MaintenanceType;
use Illuminate\Database\Seeder;

/**
 * Reference checklists for the preventive maintenance types (SRS FR-MNT-004).
 *
 * `MaintenanceTypeSeeder` has seeded five types since the baseline, three of
 * them preventive, but no template ever existed — so the required-item gate the
 * requirement describes had nothing to enforce. These are that starting set.
 *
 * Deliberately modest. A checklist a technician actually completes is worth more
 * than an exhaustive one they learn to tick through, and an Administrator can
 * edit templates at runtime; seeding a long list would only mean the first
 * administrative act is deleting most of it.
 *
 * **Required** items are the ones whose omission would make the visit
 * unverifiable or leave the machine unsafe — not merely important ones. Every
 * required item blocks completion (`MaintenanceLifecycle`), so marking
 * everything required would turn the gate into an obstacle people route around.
 *
 * Idempotent on the template name and on `(template, label)`, like every other
 * reference seeder here, so re-seeding an existing deployment neither duplicates
 * rows nor discards administrator edits to the item set.
 */
class ChecklistTemplateSeeder extends Seeder
{
    /**
     * type slug => [template name, [[label, required], …]].
     *
     * @var array<string, array{0: string, 1: list<array{0: string, 1: bool}>}>
     */
    private array $templates = [
        'preventive' => ['Preventive Maintenance — Standard', [
            ['Verify the unit powers on and reaches the desktop', true],
            ['Check cooling: fans spin, vents clear, no thermal alarms', true],
            ['Confirm operating system and security updates are current', false],
            ['Check available storage and clear temporary files', false],
            ['Verify network connectivity and shared resources', false],
            ['Confirm peripherals respond (monitor, keyboard, mouse)', false],
            ['Record the unit condition after the visit', true],
        ]],
        'inspection' => ['Inspection — Standard', [
            ['Confirm the asset tag and QR label are present and legible', true],
            ['Confirm the unit is in its recorded room', true],
            ['Inspect cabling and power connections for damage', true],
            ['Note any physical damage or missing components', false],
            ['Confirm the installed components match the specification', false],
        ]],
        'cleaning' => ['Cleaning — Standard', [
            ['Power down and disconnect the unit before cleaning', true],
            ['Clear dust from intakes, exhausts and heatsinks', true],
            ['Clean the exterior chassis, monitor and peripherals', false],
            ['Verify the unit powers on normally after reassembly', true],
        ]],
    ];

    public function run(): void
    {
        foreach ($this->templates as $typeSlug => [$name, $items]) {
            $type = MaintenanceType::query()->where('slug', $typeSlug)->first();

            if ($type === null) {
                // The type seeder owns the vocabulary; a missing slug means the
                // catalogue was customized, not that this seeder should invent one.
                continue;
            }

            $template = ChecklistTemplate::query()->updateOrCreate(
                ['name' => $name],
                [
                    'maintenance_type_id' => $type->getKey(),
                    'description' => "Default checklist issued with every {$type->name} record.",
                    'is_active' => true,
                ],
            );

            foreach ($items as $index => [$label, $required]) {
                ChecklistTemplateItem::query()->updateOrCreate(
                    ['checklist_template_id' => $template->getKey(), 'label' => $label],
                    ['sort_order' => $index, 'is_required' => $required],
                );
            }

            // Bind the template as the type's default so a new record of this
            // type is issued with it automatically (FR-MNT-004). Written only
            // when unset, so an administrator who pointed the type at their own
            // template keeps it across re-seeds.
            if ($type->default_checklist_template_id === null) {
                $type->forceFill(['default_checklist_template_id' => $template->getKey()])->save();
            }
        }
    }
}
