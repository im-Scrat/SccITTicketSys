<?php

declare(strict_types=1);

use App\Domains\Assets\Jobs\ImportAssetRowsJob;
use App\Domains\Assets\Jobs\ReadAssetImportFileJob;
use App\Domains\Assets\Jobs\RunAssetImportJob;
use App\Domains\Assets\Jobs\ValidateAssetImportJob;
use App\Enums\ActivityAction;
use App\Enums\AssetImportOutcome;
use App\Enums\AssetImportRowOutcome;
use App\Enums\AssetImportStatus;
use App\Enums\ComponentType;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetImport;
use App\Models\HardwareComponent;
use App\Models\HardwareModel;
use App\Models\Manufacturer;
use App\Models\PcUnit;
use App\Models\Role;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssetImportFixtures;

/*
 * Phase 2.10 R6 — confirming and saving (owner decision D3, SDD DD-98).
 *
 * A confirm carries the plan the administrator saw and is refused when it
 * changed; each row saves on its own, through the same Action as the form,
 * with the same side effects; a duplicate that appears between preview and
 * save is skipped and makes the outcome partial; stopping and losing access
 * stop at a chunk boundary and keep what was created; retry reopens only the
 * rows that did not save. One outcome, computed once.
 */

beforeEach(function (): void {
    seedRbac();
    Storage::fake('local');
    $this->admin = userWithRole('administrator');
    $this->estate = AssetImportFixtures::estate();

    $this->ready = function (array $rows, string $mode = 'pc_units'): AssetImport {
        $import = AssetImportFixtures::upload($this->admin, AssetImportFixtures::csv($rows), $mode);
        if ($import->refresh()->status === AssetImportStatus::Mapping) {
            AssetImportFixtures::map($this->admin, $import)->assertOk();
        }

        return $import->refresh();
    };

    /*
     * With the queue faked, run what was pushed — and what that pushes — one
     * job at a time, so a test can act between chunks.
     */
    $this->ran = [];
    $this->step = function (): bool {
        // pushedJobs() groups by class, not time: track what ran by identity.
        foreach (Queue::pushedJobs() as $jobs) {
            foreach ($jobs as ['job' => $job]) {
                if (! isset($this->ran[spl_object_id($job)])) {
                    $this->ran[spl_object_id($job)] = true;
                    // A worker releases a unique job's lock as it processes it.
                    if ($job instanceof ShouldBeUnique || $job instanceof ShouldBeUniqueUntilProcessing) {
                        (new UniqueLock(app(Cache::class)))->release($job);
                    }
                    app()->call([$job, 'handle']);

                    return true;
                }
            }
        }

        return false;
    };
    $this->drain = function (): void {
        while (($this->step)()) {
        }
    };
    $this->pcRows = fn (int $n): array => [['Unit code', 'Name', 'Room code'], ...array_map(fn (int $i): array => ["PC-{$i}", "PC {$i}", 'LAB-1'], range(1, $n))];
});

/* ------------------------------------------------------------------ confirm */

it('refuses a confirm whose revision or counts are out of date, with the plan as it is now', function (): void {
    $import = ($this->ready)(($this->pcRows)(2));
    $plan = $this->actingAs($this->admin)->getJson("/api/admin/asset-imports/{$import->uuid}")->json('data.plan');
    $uri = "/api/admin/asset-imports/{$import->uuid}/confirm";

    $this->actingAs($this->admin)->postJson($uri, ['revision' => $plan['revision'] - 1, 'expected' => ['pc_units' => 2, 'assets' => 0, 'catalog_entries' => 0]])
        ->assertStatus(409)->assertJsonPath('code', 'plan_changed')->assertJsonPath('plan.pc_units', 2);
    $this->actingAs($this->admin)->postJson($uri, ['revision' => $plan['revision'], 'expected' => ['pc_units' => 3, 'assets' => 0, 'catalog_entries' => 0]])
        ->assertStatus(409)->assertJsonPath('code', 'plan_changed');

    expect(PcUnit::query()->count())->toBe(0)->and($import->refresh()->status)->toBe(AssetImportStatus::Review);
});

it('refuses a confirm while a row still needs a decision, or when nothing would be imported', function (): void {
    $blocked = ($this->ready)([['Unit code', 'Name', 'Room code'], ['PC-1', '', 'LAB-1']]);
    AssetImportFixtures::confirm($this->admin, $blocked)->assertUnprocessable()->assertJsonValidationErrors('import');

    PcUnit::factory()->create(['unit_code' => 'PC-1']);
    $nothing = ($this->ready)([['Unit code', 'Name', 'Room code'], ['PC-1', 'Again', 'LAB-1']]);
    AssetImportFixtures::confirm($this->admin, $nothing)->assertUnprocessable();
});

it('treats a repeated confirm of the same plan as the same request', function (): void {
    Queue::fake();
    $import = AssetImportFixtures::upload($this->admin, AssetImportFixtures::csv(($this->pcRows)(2)));
    ($this->drain)();

    $first = AssetImportFixtures::confirm($this->admin, $import->refresh())->assertStatus(202);
    $plan = $first->json('data.plan');
    $this->actingAs($this->admin)->postJson("/api/admin/asset-imports/{$import->uuid}/confirm", [
        'revision' => $plan['revision'], 'expected' => ['pc_units' => 2, 'assets' => 0, 'catalog_entries' => 0],
    ])->assertStatus(202);

    Queue::assertPushed(RunAssetImportJob::class, 1);
    ($this->drain)();
    expect(PcUnit::query()->count())->toBe(2)->and($import->refresh()->outcome)->toBe(AssetImportOutcome::Complete);
});

/* --------------------------------------------------------------------- save */

it('creates records with exactly the side effects of a single create', function (): void {
    $model = HardwareModel::factory()->create(['model_name' => 'L3210']);
    $model->component->update(['component_type' => ComponentType::Printer, 'manufacturer_id' => Manufacturer::factory()->create(['name' => 'Epson'])->id]);

    $import = ($this->ready)([
        ['Asset tag', 'Category', 'Brand', 'Model', 'Status', 'Room code'],
        ['a-100', 'Printer', 'Epson', 'L3210', 'In Service', 'LAB-1'],
    ], 'assets');
    AssetImportFixtures::confirm($this->admin, $import)->assertStatus(202);

    $asset = Asset::query()->where('asset_tag', 'A-100')->firstOrFail();
    expect($asset->hardware_model_id)->toBe($model->id)
        ->and($asset->status->value)->toBe('deployed')
        ->and($asset->current_room_id)->toBe($this->estate['lab1']->id)
        ->and(DB::table('asset_status_history')->where('asset_id', $asset->id)->count())->toBe(1)
        ->and(ActivityLog::query()->where('action', ActivityAction::AssetCreated->value)->where('subject_id', $asset->id)->value('properties'))
        ->toHaveKey('import');
});

it('creates an approved catalog entry once, after confirm, and reuses it next time', function (): void {
    $rows = [['Asset tag', 'Category', 'Brand', 'Model', 'Room code'], ['P-1', 'Projector', 'BenQ', 'MX550', 'LAB-1']];

    $import = ($this->ready)($rows, 'assets');
    $entry = $import->catalogEntries()->firstOrFail();
    $this->actingAs($this->admin)->putJson("/api/admin/asset-imports/{$import->uuid}/catalog/{$entry->uuid}", ['decision' => 'approved'])->assertOk();
    expect(HardwareModel::query()->count())->toBe(0);

    AssetImportFixtures::confirm($this->admin, $import->refresh())->assertStatus(202);

    $model = HardwareModel::query()->where('model_name', 'MX550')->firstOrFail();
    expect(Asset::query()->where('asset_tag', 'P-1')->value('hardware_model_id'))->toBe($model->id)
        ->and($model->component->component_type)->toBe(ComponentType::Projector)
        ->and($model->component->manufacturer->name)->toBe('BenQ')
        ->and(ActivityLog::query()->where('action', ActivityAction::CatalogEntryCreated->value)->count())->toBe(1)
        ->and($import->refresh()->result['catalog_created'])->toBe(1);

    // The same product in a later file is now simply in the catalog.
    $again = ($this->ready)([['Asset tag', 'Category', 'Brand', 'Model', 'Room code'], ['P-2', 'Projector', 'benq', 'mx550', 'LAB-1']], 'assets');
    expect($again->catalogEntries()->firstOrFail()->matched_model_id)->toBe($model->id)
        ->and($again->row_counts['ready'])->toBe(1);
    AssetImportFixtures::confirm($this->admin, $again)->assertStatus(202);
    expect(HardwareModel::query()->count())->toBe(1)->and(Manufacturer::query()->count())->toBe(1)->and(HardwareComponent::query()->count())->toBe(1);
});

it('skips a duplicate that appeared after the preview, and calls the result partial', function (): void {
    Queue::fake();
    $import = AssetImportFixtures::upload($this->admin, AssetImportFixtures::csv(($this->pcRows)(2)));
    ($this->drain)();
    AssetImportFixtures::confirm($this->admin, $import->refresh())->assertStatus(202);

    PcUnit::factory()->create(['unit_code' => 'pc-2']);
    ($this->drain)();

    $import->refresh();
    expect($import->outcome)->toBe(AssetImportOutcome::Partial)
        ->and($import->result['headline'])->toBe('Partially imported — 1 of 2 created')
        ->and($import->rows()->where('row_number', 3)->value('failure_code'))->toBe('duplicate_at_save')
        ->and(PcUnit::query()->count())->toBe(2);
});

it('calls it no new records when every row turned out to exist already', function (): void {
    Queue::fake();
    $import = AssetImportFixtures::upload($this->admin, AssetImportFixtures::csv(($this->pcRows)(1)));
    ($this->drain)();
    AssetImportFixtures::confirm($this->admin, $import->refresh())->assertStatus(202);
    PcUnit::factory()->create(['unit_code' => 'PC-1']);
    ($this->drain)();

    expect($import->refresh()->outcome)->toBe(AssetImportOutcome::NothingNew)
        ->and($import->result['tone'])->toBe('neutral');
});

it('fails only the row whose room went away, and retries just that row', function (): void {
    Queue::fake();
    $file = AssetImportFixtures::csv([['Unit code', 'Name', 'Room code'], ['PC-1', 'One', 'LAB-1'], ['PC-2', 'Two', 'LAB-2']]);
    $import = AssetImportFixtures::upload($this->admin, $file);
    ($this->drain)();
    AssetImportFixtures::confirm($this->admin, $import->refresh())->assertStatus(202);

    $this->estate['lab2']->delete();
    ($this->drain)();

    $import->refresh();
    expect($import->outcome)->toBe(AssetImportOutcome::Partial)
        ->and($import->rows()->where('row_number', 3)->value('failure_code'))->toBe('room_unavailable');

    $this->actingAs($this->admin)->postJson("/api/admin/asset-imports/{$import->uuid}/retry")->assertOk();
    $row = $import->rows()->where('row_number', 3)->firstOrFail();
    expect($import->refresh()->status)->toBe(AssetImportStatus::Review)
        ->and($row->previous_failure_code)->toBe('room_unavailable')
        ->and($row->outcome)->toBeNull();

    $location = $import->locations()->where('normalized', 'like', '%lab-2%')->firstOrFail();
    $this->actingAs($this->admin)->putJson("/api/admin/asset-imports/{$import->uuid}/locations/{$location->uuid}", [
        'resolution' => 'room', 'room' => $this->estate['lab1']->uuid,
    ])->assertOk();

    $plan = $this->actingAs($this->admin)->getJson("/api/admin/asset-imports/{$import->uuid}")->json('data.plan');
    expect($plan['pc_units'])->toBe(1);
    AssetImportFixtures::confirm($this->admin, $import)->assertStatus(202);
    ($this->drain)();

    expect($import->refresh()->outcome)->toBe(AssetImportOutcome::Complete)
        ->and(PcUnit::query()->count())->toBe(2)
        ->and(ActivityLog::query()->where('action', ActivityAction::AssetImportRetried->value)->count())->toBe(1);
});

it('stops at the next chunk when asked, keeping what was created', function (): void {
    config(['assets.import.chunk_rows' => 1]);
    Queue::fake();
    $import = AssetImportFixtures::upload($this->admin, AssetImportFixtures::csv(($this->pcRows)(3)));
    ($this->drain)();
    AssetImportFixtures::confirm($this->admin, $import->refresh())->assertStatus(202);

    ($this->step)(); // start
    ($this->step)(); // first row
    $this->actingAs($this->admin)->postJson("/api/admin/asset-imports/{$import->uuid}/cancel")->assertOk();
    ($this->drain)();

    $import->refresh();
    expect($import->outcome)->toBe(AssetImportOutcome::Stopped)
        ->and($import->result['headline'])->toBe('Import stopped — 1 created')
        ->and(PcUnit::query()->count())->toBe(1)
        ->and($import->rows()->where('outcome', AssetImportRowOutcome::NotAttempted->value)->count())->toBe(2);
});

it('stops when the confirming administrator loses access mid-import', function (): void {
    config(['assets.import.chunk_rows' => 1]);
    Queue::fake();
    $import = AssetImportFixtures::upload($this->admin, AssetImportFixtures::csv(($this->pcRows)(3)));
    ($this->drain)();
    AssetImportFixtures::confirm($this->admin, $import->refresh())->assertStatus(202);

    ($this->step)();
    ($this->step)();
    $this->admin->update(['role_id' => Role::query()->where('slug', 'technician')->value('id')]);
    ($this->drain)();

    $import->refresh();
    expect($import->outcome)->toBe(AssetImportOutcome::Partial)
        ->and(PcUnit::query()->count())->toBe(1)
        ->and($import->rows()->where('failure_code', 'actor_not_authorised')->count())->toBe(2);
});

it('keeps only the mapped columns\' cells once finished', function (): void {
    $import = ($this->ready)([['Unit code', 'Name', 'Room code', 'Colour'], ['PC-1', 'One', 'LAB-1', 'Blue']]);
    AssetImportFixtures::confirm($this->admin, $import)->assertStatus(202);

    $raw = $import->rows()->firstOrFail()->raw;
    expect($raw)->toHaveKeys(['0', '1', '2'])->and($raw)->not->toHaveKey('3');
});

/* ------------------------------------------------------------------- cancel */

it('purges a cancelled draft and its file, keeping the import as a record', function (): void {
    $import = ($this->ready)(($this->pcRows)(2));
    Storage::disk('local')->assertExists($import->path);

    $this->actingAs($this->admin)->postJson("/api/admin/asset-imports/{$import->uuid}/cancel")->assertOk();

    $import->refresh();
    expect($import->status)->toBe(AssetImportStatus::Cancelled)
        ->and($import->rows()->count())->toBe(0)
        ->and($import->file_deleted_at)->not->toBeNull();
    Storage::disk('local')->assertMissing($import->path);
});

/* -------------------------------------------------------------- maintenance */

it('expires old drafts, deletes old files, and resumes a stalled save', function (): void {
    $draft = ($this->ready)(($this->pcRows)(1));
    $draft->forceFill(['expires_at' => now()->subMinute()])->save();

    $finished = ($this->ready)([['Unit code', 'Name', 'Room code'], ['PC-9', 'Nine', 'LAB-1']]);
    AssetImportFixtures::confirm($this->admin, $finished)->assertStatus(202);
    $finished->refresh()->forceFill(['finished_at' => now()->subDays(31)])->save();

    $stalled = AssetImport::factory()->create(['status' => AssetImportStatus::Importing, 'heartbeat_at' => now()->subHour()]);

    Queue::fake();
    $this->artisan('assets:imports-maintain')->assertSuccessful();

    expect($draft->refresh()->status)->toBe(AssetImportStatus::Expired)
        ->and($draft->rows()->count())->toBe(0)
        ->and($finished->refresh()->file_deleted_at)->not->toBeNull()
        ->and($finished->rows()->first()->raw)->toBeNull()
        ->and(PcUnit::query()->where('unit_code', 'PC-9')->exists())->toBeTrue();
    Queue::assertPushed(ImportAssetRowsJob::class, fn (ImportAssetRowsJob $job): bool => $job->importId === $stalled->id);
    Queue::assertNotPushed(ReadAssetImportFileJob::class);
    Queue::assertNotPushed(ValidateAssetImportJob::class);
});
