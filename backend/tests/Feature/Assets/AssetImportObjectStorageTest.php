<?php

declare(strict_types=1);

use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\DecoratedAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\Support\AssetImportFixtures;

/*
 * Imports on object storage (the Render pilot keeps uploads in an S3 bucket,
 * because its container disk does not survive a restart). The spreadsheet
 * reader needs a real file path, which an object store does not have: the
 * reader must work from a temporary local copy and remove it afterwards.
 *
 * The "object store" here is a local directory behind a decorating adapter —
 * anything that is not a LocalFilesystemAdapter takes the copy path, exactly
 * as the S3 adapter does, without a network.
 */

beforeEach(function (): void {
    seedRbac();
    Storage::fake('local');

    $root = storage_path('framework/testing/disks/object-store');
    File::deleteDirectory($root);
    $inner = new LocalFilesystemAdapter($root);
    $objectStore = new class($inner) extends DecoratedAdapter {};
    Storage::set('object-store', new FilesystemAdapter(new Filesystem($objectStore), $objectStore, ['root' => $root]));

    config(['filesystems.attachments' => 'object-store']);
    $this->admin = userWithRole('administrator');
});

afterEach(fn () => File::deleteDirectory(storage_path('framework/testing/disks/object-store')));

it('stores the upload on the configured disk and reads it through a temporary copy', function (): void {
    $before = glob(sys_get_temp_dir().'/sccit-import-*') ?: [];

    $this->actingAs($this->admin)
        ->post('/api/admin/asset-imports', ['file' => AssetImportFixtures::csv([['Unit code', 'Name'], ['PC-1', 'One'], ['PC-2', 'Two']]), 'entity_mode' => 'pc_units'], ['Accept' => 'application/json'])
        ->assertCreated();

    $import = AssetImport::query()->sole();

    expect($import->disk)->toBe('object-store')
        ->and(Storage::disk('object-store')->exists((string) $import->path))->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($import->status)->not->toBe(AssetImportStatus::Unreadable)
        ->and($import->rows()->count())->toBe(2);

    // The temporary copy is gone once read.
    expect(glob(sys_get_temp_dir().'/sccit-import-*') ?: [])->toBe($before);
});
