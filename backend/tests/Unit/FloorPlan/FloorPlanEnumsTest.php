<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\PcStatus;

/**
 * WP-B — the enum surface the floor plan renders from.
 */
it('gives every PC status a label and one of the three status tones', function (): void {
    foreach (PcStatus::cases() as $status) {
        expect($status->label())->toBeString()->not->toBe('')
            ->and($status->tone())->toBeIn(['success', 'warning', 'danger']);
    }
});

it('pins the three repair statuses, their labels and tones (Phase 2.11, DD-100)', function (PcStatus $status, string $label, string $tone): void {
    expect($status->label())->toBe($label)
        ->and($status->tone())->toBe($tone);
})->with([
    'available' => [PcStatus::Available, 'Available', 'success'],
    'pending repair' => [PcStatus::PendingRepair, 'Pending Repair', 'warning'],
    'needs fix' => [PcStatus::NeedsFix, 'Needs Fix', 'danger'],
]);

it('has exactly three stored values — Unassigned is derived, never stored', function (): void {
    expect(PcStatus::values())->toBe(['available', 'pending_repair', 'needs_fix']);
});

it('reads every legacy value as the value the migration mapped it to', function (string $legacy, PcStatus $expected): void {
    expect(PcStatus::fromStored($legacy))->toBe($expected);
})->with([
    ['available', PcStatus::Available],
    ['assigned', PcStatus::Available],
    ['online', PcStatus::Available],
    ['under_maintenance', PcStatus::PendingRepair],
    ['offline', PcStatus::NeedsFix],
    ['retired', PcStatus::NeedsFix],
    ['pending_repair', PcStatus::PendingRepair],
]);

it('still labels legacy values found in history, and never throws on an unknown one', function (): void {
    expect(PcStatus::labelFor('online'))->toBe('Online')
        ->and(PcStatus::labelFor('under_maintenance'))->toBe('Under Maintenance')
        ->and(PcStatus::labelFor('needs_fix'))->toBe('Needs Fix')
        ->and(PcStatus::labelFor('something_else'))->toBe('something_else')
        ->and(PcStatus::labelFor(null))->toBeNull();
});

it('does not rely on tone alone to tell the status states apart', function (): void {
    // FR-FP-004: colour is never the only signal. Distinct labels are what the
    // client pairs with tone and icon, so they must be unique — and so must
    // the tones, so no two statuses share a colour.
    $labels = array_map(static fn (PcStatus $s): string => $s->label(), PcStatus::cases());
    $tones = array_map(static fn (PcStatus $s): string => $s->tone(), PcStatus::cases());

    expect($labels)->toBe(array_values(array_unique($labels)))
        ->and($tones)->toBe(array_values(array_unique($tones)));
});

it('offers the five floor-plan audit actions with labels', function (string $case, string $value, string $label): void {
    $action = constant(ActivityAction::class.'::'.$case);

    expect($action->value)->toBe($value)
        ->and($action->label())->toBe($label)
        ->and(ActivityAction::from($value))->toBe($action);
})->with([
    ['LayoutCreated', 'layout_created', 'Layout created'],
    ['LayoutActivated', 'layout_activated', 'Layout activated'],
    ['LayoutUpdated', 'layout_updated', 'Layout updated'],
    ['AssetPositionChanged', 'asset_position_changed', 'Position changed'],
    ['AssetPositionCleared', 'asset_position_cleared', 'Position cleared'],
]);

it('keeps activity action values unique and every label non-empty', function (): void {
    $values = ActivityAction::values();

    expect($values)->toBe(array_values(array_unique($values)));

    foreach (ActivityAction::cases() as $action) {
        expect($action->label())->not->toBe('');
    }
});
