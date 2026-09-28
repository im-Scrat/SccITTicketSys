<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\PcStatus;

/**
 * WP-B — the enum surface the floor plan renders from.
 */
it('gives every PC status a label and one of the four API tones', function (): void {
    foreach (PcStatus::cases() as $status) {
        expect($status->label())->toBeString()->not->toBe('')
            ->and($status->tone())->toBeIn(['neutral', 'success', 'warning', 'danger']);
    }
});

it('pins the label and tone of each PC status', function (PcStatus $status, string $label, string $tone): void {
    expect($status->label())->toBe($label)
        ->and($status->tone())->toBe($tone);
})->with([
    'available' => [PcStatus::Available, 'Available', 'neutral'],
    'assigned' => [PcStatus::Assigned, 'Assigned', 'neutral'],
    'online' => [PcStatus::Online, 'Online', 'success'],
    'offline' => [PcStatus::Offline, 'Offline', 'danger'],
    'under maintenance' => [PcStatus::UnderMaintenance, 'Under Maintenance', 'warning'],
    'retired' => [PcStatus::Retired, 'Retired', 'neutral'],
]);

it('keeps the labels the analytics status mix already shows', function (): void {
    // The explicit label() replaced the HasValues default, which derived the
    // wording from the backing value. Anything that read the default must see
    // the same strings, or a dashboard changes as a side effect of this change.
    foreach (PcStatus::cases() as $status) {
        expect($status->label())->toBe(ucwords(str_replace('_', ' ', $status->value)));
    }
});

it('does not rely on tone alone to tell the status states apart', function (): void {
    // FR-FP-004: colour is never the only signal. Distinct labels are what the
    // client pairs with tone and shape, so they must be unique.
    $labels = array_map(static fn (PcStatus $s): string => $s->label(), PcStatus::cases());

    expect($labels)->toBe(array_values(array_unique($labels)));
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
