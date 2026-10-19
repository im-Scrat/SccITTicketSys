<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Services;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceType;

/**
 * Issues a maintenance record its checklist (SRS FR-MNT-004).
 *
 * The template is the *catalogue*; the instantiated rows are the *work*. That
 * distinction is the whole design here, and it is why `is_required`, `label`
 * and `sort_order` are **copied onto the instance** rather than read back
 * through `checklist_template_item_id`:
 *
 *  - the FK is `ON DELETE SET NULL`, so deleting a template item would
 *    otherwise disarm the completion gate on every record already issued from
 *    it — the requirement would hold until an administrator tidied the
 *    catalogue;
 *  - editing a template's wording would silently rewrite the history of visits
 *    already completed against the old wording.
 *
 * The FK is still kept, because knowing which catalogue entry a line came from
 * is genuinely useful; it is simply not where the rule lives.
 */
class ChecklistInstantiator
{
    /**
     * Issue the type's default checklist to a record.
     *
     * Idempotent by refusal: a record that already carries checklist rows is
     * left alone. Re-issuing would either duplicate the list or silently discard
     * ticks a technician had already made, and neither is something a caller
     * would want by accident.
     *
     * @return int the number of items issued
     */
    public function issue(MaintenanceRecord $record, ?ChecklistTemplate $template = null): int
    {
        if ($record->checklists()->exists()) {
            return 0;
        }

        $template ??= $this->defaultTemplateFor($record->type);

        if ($template === null) {
            // Not every maintenance type carries a checklist, and a record
            // without one is perfectly valid — it simply has no required-item
            // gate to satisfy.
            return 0;
        }

        $items = $template->items()->orderBy('sort_order')->orderBy('id')->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $now = now();

        $record->checklists()->createMany(
            $items->map(fn (ChecklistTemplateItem $item): array => [
                'checklist_template_item_id' => $item->getKey(),
                'item_label' => $item->label,
                'is_required' => (bool) $item->is_required,
                'sort_order' => (int) $item->sort_order,
                'is_completed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        return $items->count();
    }

    /**
     * How many required items are still outstanding.
     *
     * Read from the instance rows for the reason above. Shared with
     * {@see MaintenanceLifecycle}'s completion gate so the count a technician
     * sees and the count that blocks them are the same number.
     */
    public function outstandingRequired(MaintenanceRecord $record): int
    {
        return $record->checklists()
            ->where('is_required', true)
            ->where('is_completed', false)
            ->count();
    }

    private function defaultTemplateFor(?MaintenanceType $type): ?ChecklistTemplate
    {
        if ($type === null || $type->default_checklist_template_id === null) {
            return null;
        }

        return ChecklistTemplate::query()
            ->where('is_active', true)
            ->find($type->default_checklist_template_id);
    }
}
