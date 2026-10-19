<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Exceptions\ImportFileRejected;
use App\Domains\Assets\Jobs\ReadAssetImportFileJob;
use App\Domains\Assets\Services\Import\SpreadsheetSafety;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\AssetImportEntityMode;
use App\Enums\AssetImportStatus;
use App\Models\AssetImport;
use App\Models\User;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Accepts an inventory spreadsheet and starts reading it (Phase 2.10 R6, SDD
 * DD-96). Nothing is added to the register here — "nothing is saved until you
 * review the rows and confirm".
 *
 * The file passes the one upload trust boundary (`AttachmentSecurity`, with
 * the spreadsheet profile): its type is read from its bytes, and that type
 * must agree with its extension. An .xlsx is then opened as the zip it is and
 * inspected by {@see SpreadsheetSafety} before it is stored. It is kept on the
 * private disk under a server-chosen path, with its sha256 — a second upload
 * of the same bytes is pointed out, not refused.
 */
final class CreateAssetImport
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SpreadsheetSafety $safety,
    ) {}

    public function handle(UploadedFile $file, AssetImportEntityMode $mode, User $actor, Request $request): AssetImport
    {
        $mime = AttachmentSecurity::detect($file, AttachmentSecurity::PROFILE_SPREADSHEET);
        $extension = strtolower($file->getClientOriginalExtension());

        if (! AttachmentSecurity::extensionMatches($mime, $extension, AttachmentSecurity::PROFILE_SPREADSHEET)) {
            throw ValidationException::withMessages([
                'file' => 'This file\'s contents do not match its name. Save it again from Excel as .xlsx or as CSV and upload that copy.',
            ]);
        }

        try {
            $this->safety->check((string) $file->getRealPath(), $extension);
        } catch (ImportFileRejected $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $uuid = (string) Str::uuid();
        $directory = "asset-imports/{$uuid}";
        $name = "source.{$extension}";
        $disk = AttachmentSecurity::storageDisk();
        $stored = Storage::disk($disk)->putFileAs($directory, $file, $name);
        if ($stored === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored. Try again.']);
        }

        $import = AssetImport::query()->create([
            'uuid' => $uuid,
            'created_by' => $actor->getKey(),
            'entity_mode' => $mode,
            'status' => AssetImportStatus::Reading,
            'original_filename' => mb_substr($this->safeName($file->getClientOriginalName()), 0, 255),
            'extension' => $extension,
            'mime' => $mime,
            'size_bytes' => (int) $file->getSize(),
            'sha256' => (string) hash_file('sha256', (string) $file->getRealPath()),
            'disk' => $disk,
            'path' => $stored,
            'expires_at' => now()->addDays((int) config('assets.import.draft_days', 7)),
        ]);

        $this->audit->activity(
            ActivityAction::AssetImportUploaded,
            actor: $actor,
            subject: $import,
            properties: [
                'import' => $import->uuid,
                'file' => $import->original_filename,
                'size_bytes' => $import->size_bytes,
                'kind' => $mode->value,
            ],
            request: $request,
            module: 'assets',
            description: "Asset import file {$import->original_filename} uploaded",
        );

        ReadAssetImportFileJob::dispatch($import->getKey());

        return $import->refresh();
    }

    /** The original name for display only: no path, no control characters. */
    private function safeName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $base);

        return $base !== '' ? $base : 'import';
    }
}
