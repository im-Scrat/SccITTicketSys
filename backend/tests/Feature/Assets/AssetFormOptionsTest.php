<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Add-asset form (owner request 2026-10-20, SDD DD-114).
 *
 * The form offers three opening statuses (Available / Under maintenance / Out
 * of service) and two conditions (Working / For repair); "Other" category and
 * "Other" supplier each open a text box. The import keeps the full vocabulary,
 * so these rules belong to the form's request only.
 */

beforeEach(function () {
    seedRbac();
    $this->admin = userWithRole('administrator');
});

it('offers the narrowed status and condition lists to the form, and the full lists elsewhere', function () {
    $catalog = $this->actingAs($this->admin)->getJson('/api/admin/assets/catalog')->assertOk()->json('data');

    expect(collect($catalog['form_statuses'])->pluck('label')->all())
        ->toBe(['Available', 'Under maintenance', 'Out of service'])
        ->and(collect($catalog['form_conditions'])->pluck('label')->all())->toBe(['Working', 'For Repair'])
        ->and(collect($catalog['statuses'])->pluck('value')->all())->toContain('new', 'deployed', 'retired')
        ->and(collect($catalog['conditions'])->pluck('value')->all())->toContain('faulty', 'decommissioned');
});

it('opens an asset in each of the three form statuses', function (string $status) {
    $this->actingAs($this->admin)->postJson('/api/admin/assets', ['status' => $status])
        ->assertCreated()
        ->assertJsonPath('data.status', $status);
})->with(['in_stock', 'in_repair', 'out_of_service']);

it('refuses an opening status or condition the form does not offer', function (array $payload, string $field) {
    $this->actingAs($this->admin)->postJson('/api/admin/assets', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'new' => [['status' => 'new'], 'status'],
    'deployed' => [['status' => 'deployed'], 'status'],
    'faulty' => [['condition' => 'faulty'], 'condition'],
    'decommissioned' => [['condition' => 'decommissioned'], 'condition'],
]);

it('lets an asset recorded earlier as faulty be edited without changing its condition', function () {
    $asset = Asset::factory()->create(['condition' => 'faulty']);

    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}", [
        'name' => 'Renamed',
        'condition' => 'faulty',
    ])->assertOk();

    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}", [
        'condition' => 'decommissioned',
    ])->assertUnprocessable()->assertJsonValidationErrors(['condition']);
});

/* ---- "Other" category --------------------------------------------------- */

it('keeps the description of an "Other" category and shows it as the category', function () {
    $response = $this->actingAs($this->admin)->postJson('/api/admin/assets', [
        'category' => 'other',
        'category_detail' => '  Smart board  ',
    ])->assertCreated();

    $response->assertJsonPath('data.category_detail', 'Smart board')
        ->assertJsonPath('data.category_label', 'Other — Smart board');
});

it('requires a description for "Other" and refuses one for any other category', function (array $payload) {
    $this->actingAs($this->admin)->postJson('/api/admin/assets', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category_detail']);
})->with([
    'missing' => [['category' => 'other']],
    'blank' => [['category' => 'other', 'category_detail' => "  \t "]],
    'too short' => [['category' => 'other', 'category_detail' => 'ab']],
    'wrong category' => [['category' => 'printer', 'category_detail' => 'Laser']],
]);

it('clears the description when an edit moves the asset out of "Other"', function () {
    $asset = Asset::factory()->create(['category_detail' => 'Smart board']);

    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}", [
        'category' => 'printer',
    ])->assertOk()->assertJsonPath('data.category_detail', null);

    expect($asset->fresh()->category_detail)->toBeNull();
});

it('leaves the description alone on a partial edit that does not name the category', function () {
    $asset = Asset::factory()->create(['category_detail' => 'Smart board']);

    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}", ['name' => 'Board 2'])->assertOk();

    expect($asset->fresh()->category_detail)->toBe('Smart board');
});

it('refuses, in the database itself, a blank category description', function () {
    $asset = Asset::factory()->create();

    expect(fn () => DB::table('assets')->where('id', $asset->id)->update(['category_detail' => "\t \n"]))
        ->toThrow(QueryException::class, 'assets_category_detail_check');
});

/* ---- "Other" supplier --------------------------------------------------- */

it('files a typed supplier and offers it next time', function () {
    $this->actingAs($this->admin)->postJson('/api/admin/assets', ['supplier_other' => 'Octagon Computer Superstore'])
        ->assertCreated();

    $supplier = Supplier::query()->where('name', 'Octagon Computer Superstore')->firstOrFail();
    expect($supplier->is_active)->toBeTrue();

    $catalog = $this->actingAs($this->admin)->getJson('/api/admin/assets/catalog')->json('data');
    expect(collect($catalog['suppliers'])->pluck('value')->all())->toContain('Octagon Computer Superstore');

    $created = ActivityLog::query()->where('action', ActivityAction::AssetCreated->value)->latest('id')->firstOrFail();
    expect($created->properties['supplier_created'] ?? null)->toBe('Octagon Computer Superstore');
});

it('reuses an existing supplier whatever the letter case, instead of filing a duplicate', function () {
    $existing = Supplier::factory()->create(['name' => 'PC Express']);

    $response = $this->actingAs($this->admin)->postJson('/api/admin/assets', ['supplier_other' => 'pc express'])
        ->assertCreated();

    expect(Supplier::query()->whereRaw('lower(name) = ?', ['pc express'])->count())->toBe(1)
        ->and(Asset::query()->where('uuid', $response->json('data.id'))->value('supplier_id'))->toBe($existing->id);
});

it('refuses a chosen and a typed supplier together', function () {
    $supplier = Supplier::factory()->create();

    $this->actingAs($this->admin)->postJson('/api/admin/assets', [
        'supplier' => $supplier->name,
        'supplier_other' => 'Somewhere Else',
    ])->assertUnprocessable()->assertJsonValidationErrors(['supplier_other']);
});

it('lets an edit switch the asset to a typed supplier', function () {
    $asset = Asset::factory()->create();

    $this->actingAs($this->admin)->putJson("/api/admin/assets/{$asset->uuid}", [
        'supplier' => null,
        'supplier_other' => 'Villman',
    ])->assertOk();

    expect($asset->fresh()->supplier?->name)->toBe('Villman');
});
