<?php

declare(strict_types=1);

use App\Domains\Identity\Services\PermissionResolver;
use App\Enums\PermissionGrantType;
use App\Models\AssetImport;
use App\Models\PcUnit;
use App\Models\RoomLayout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssetImportFixtures;

/*
 * Phase 2.10 R7 — the post-import review (SRS FR-AST-017, SDD DD-99).
 *
 * The slides come from the ledger and the viewer's own gates; the directory
 * filter that shows "only this import" belongs to the import's audience;
 * reading the review writes nothing, and acknowledging it writes the receipt
 * and nothing else.
 */

beforeEach(function (): void {
    seedRbac();
    Storage::fake('local');
    $this->admin = userWithRole('administrator');
    $this->estate = AssetImportFixtures::estate();

    $this->imported = function (array $rows, string $mode = 'pc_units'): AssetImport {
        $import = AssetImportFixtures::upload($this->admin, AssetImportFixtures::csv($rows), $mode);
        if ($import->refresh()->status->value === 'mapping') {
            AssetImportFixtures::map($this->admin, $import)->assertOk();
        }
        foreach ($import->catalogEntries()->where('decision', 'pending')->get() as $entry) {
            $this->actingAs($this->admin)->putJson("/api/admin/asset-imports/{$import->uuid}/catalog/{$entry->uuid}", ['decision' => 'approved'])->assertOk();
        }
        AssetImportFixtures::confirm($this->admin, $import->refresh())->assertStatus(202);

        return $import->refresh();
    };
});

it('lists the pages the records now appear on, for this viewer', function (): void {
    RoomLayout::factory()->create(['room_id' => $this->estate['lab1']->id, 'is_active' => true]);
    $import = ($this->imported)([
        ['Category', 'Unit code', 'Asset tag', 'Name', 'Model', 'Room code', 'Custodian email', 'RAM'],
        ['Desktop', 'PC-1', '', 'One', '', 'LAB-1', '', '16 GB'],
        ['Desktop', 'PC-2', '', 'Two', '', 'LAB-2', '', ''],
        ['Printer', '', 'EQ-1', 'Printer', 'L3210', 'LAB-1', $this->admin->email, ''],
    ], 'mixed');

    $review = $this->actingAs($this->admin)->getJson("/api/admin/asset-imports/{$import->uuid}/review")->assertOk()->json('data');
    $kinds = array_column($review['slides'], 'kind');

    expect($kinds)->toBe(['pc_unit_directory', 'pc_unit_detail', 'asset_directory', 'asset_detail', 'room_detail', 'locations_overview', 'floor_plan_room', 'asset_dashboard', 'reports_equipment', 'my_assets'])
        ->and($review['reviewable'])->toBeTrue()
        ->and($review['receipt']['covers_all'])->toBeFalse()
        ->and($review['highlight_values'])->toContain('PC-1', 'One', '16 GB', 'EQ-1');

    $slides = collect($review['slides'])->keyBy('kind');
    expect(array_column($slides['pc_unit_detail']['views'], 'key'))->toBe(['general', 'specifications'])
        ->and($slides['pc_unit_detail']['instance_total'])->toBe(2)
        ->and($slides['room_detail']['instances'][0]['hint'])->toBe('+1 PC unit · +1 asset from this import')
        ->and($slides['floor_plan_room']['instances'][0]['hint'])->toBe('1 PC unit not on the plan yet')
        ->and($slides['pc_unit_directory']['path'])->toBe("/app/assets/list?tab=pc-units&import={$import->uuid}");
});

it('leaves out pages the viewer may not open', function (): void {
    $import = ($this->imported)([['Unit code', 'Name', 'Room code'], ['PC-1', 'One', 'LAB-1'], ['PC-2', 'Two', 'LAB-2']]);
    givePermission($this->admin, 'locations.view', PermissionGrantType::Deny);
    givePermission($this->admin, 'reports.view', PermissionGrantType::Deny);
    app(PermissionResolver::class)->forget($this->admin);

    $kinds = array_column($this->actingAs($this->admin)->getJson("/api/admin/asset-imports/{$import->uuid}/review")->json('data.slides'), 'kind');

    expect($kinds)->toBe(['pc_unit_directory', 'pc_unit_detail']);
});

it('shows only this import\'s records in the directory, and only to the import\'s audience', function (): void {
    PcUnit::factory()->create(['unit_code' => 'OTHER-1']);
    $import = ($this->imported)([['Unit code', 'Name', 'Room code'], ['PC-1', 'One', 'LAB-1']]);

    $this->actingAs($this->admin)->getJson("/api/admin/pc-units?import={$import->uuid}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.unit_code', 'PC-1');
    $this->actingAs($this->admin)->getJson("/api/admin/assets?import={$import->uuid}")->assertOk()->assertJsonCount(0, 'data');

    $technician = userWithRole('technician');
    givePermission($technician, 'assets.view');
    app(PermissionResolver::class)->forget($technician);
    $this->actingAs($technician)->getJson('/api/admin/pc-units')->assertOk();
    $this->actingAs($technician)->getJson("/api/admin/pc-units?import={$import->uuid}")->assertUnprocessable()->assertJsonValidationErrors('import');
});

it('writes nothing when the review is read', function (): void {
    $import = ($this->imported)([['Unit code', 'Name', 'Room code'], ['PC-1', 'One', 'LAB-1']]);
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) && ! str_contains($query->sql, 'personal_access_tokens') && ! str_contains($query->sql, 'sessions')) {
            $writes[] = $query->sql;
        }
    });

    $this->actingAs($this->admin)->getJson("/api/admin/asset-imports/{$import->uuid}/review")->assertOk();

    expect($writes)->toBe([]);
});

it('acknowledges by writing the three receipt columns once, re-armed only by more records', function (): void {
    $import = ($this->imported)([['Unit code', 'Name', 'Room code'], ['PC-1', 'One', 'LAB-1']]);
    $updatedAt = DB::table('asset_imports')->where('id', $import->id)->value('updated_at');

    $this->travel(5)->minutes();
    $this->actingAs($this->admin)->postJson("/api/admin/asset-imports/{$import->uuid}/review/acknowledge")
        ->assertOk()->assertJsonPath('data.covers_all', true);

    $row = DB::table('asset_imports')->where('id', $import->id)->first();
    expect($row->reviewed_by)->toBe($this->admin->id)
        ->and($row->reviewed_created_count)->toBe(1)
        ->and($row->updated_at)->toBe($updatedAt);

    $first = $row->reviewed_at;
    $this->travel(5)->minutes();
    $this->actingAs($this->admin)->postJson("/api/admin/asset-imports/{$import->uuid}/review/acknowledge")->assertOk();
    expect(DB::table('asset_imports')->where('id', $import->id)->value('reviewed_at'))->toBe($first);

    // A retry that adds records needs a new acknowledgement.
    DB::table('asset_imports')->where('id', $import->id)->update(['reviewed_created_count' => 0]);
    $this->actingAs($this->admin)->getJson("/api/admin/asset-imports/{$import->uuid}/review")->assertJsonPath('data.receipt.covers_all', false);
});

it('refuses the review to anyone outside the import\'s audience', function (): void {
    $import = ($this->imported)([['Unit code', 'Name', 'Room code'], ['PC-1', 'One', 'LAB-1']]);

    foreach (['technician', 'teacher'] as $role) {
        $user = userWithRole($role);
        $this->actingAs($user)->getJson("/api/admin/asset-imports/{$import->uuid}/review")->assertForbidden();
        $this->actingAs($user)->postJson("/api/admin/asset-imports/{$import->uuid}/review/acknowledge")->assertForbidden();
    }
});
