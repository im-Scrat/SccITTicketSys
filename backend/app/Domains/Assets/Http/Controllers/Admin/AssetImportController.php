<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers\Admin;

use App\Domains\Assets\Actions\AcknowledgeImportReview;
use App\Domains\Assets\Actions\CancelAssetImport;
use App\Domains\Assets\Actions\ConfirmAssetImport;
use App\Domains\Assets\Actions\CreateAssetImport;
use App\Domains\Assets\Actions\RetryAssetImport;
use App\Domains\Assets\Actions\UpdateAssetImportMapping;
use App\Domains\Assets\Actions\UpdateAssetImportSource;
use App\Domains\Assets\Exports\AssetImportTemplateExport;
use App\Domains\Assets\Http\Requests\ConfirmAssetImportRequest;
use App\Domains\Assets\Http\Requests\ImportRoomOptionsRequest;
use App\Domains\Assets\Http\Requests\StoreAssetImportRequest;
use App\Domains\Assets\Http\Requests\UpdateAssetImportMappingRequest;
use App\Domains\Assets\Http\Requests\UpdateAssetImportSourceRequest;
use App\Domains\Assets\Http\Resources\AssetImportListResource;
use App\Domains\Assets\Http\Resources\AssetImportResource;
use App\Domains\Assets\Services\Import\AssetImportValues;
use App\Domains\Assets\Services\Import\ImportFieldDictionary;
use App\Domains\Assets\Services\Import\ImportLocationMatcher;
use App\Domains\Assets\Services\Import\ImportReviewBuilder;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\AssetImportEntityMode;
use App\Enums\ImportDateFormat;
use App\Http\Controllers\Controller;
use App\Models\AssetImport;
use App\Models\Room;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The asset import (Phase 2.10 R6, SRS FR-AST-016, SDD DD-96): upload, map,
 * confirm, stop, retry, and the history.
 *
 * The whole `/api/admin/asset-imports` group is closed by
 * `can:viewAny,AssetImport` and every write by `can:manage`; the FormRequests
 * check again. Imports are addressed by uuid only. Any authorised
 * administrator may work on any import — the ledger names who did what.
 */
class AssetImportController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AssetImport::class);

        return AssetImportListResource::collection(
            AssetImport::query()->with('creator')->latest('id')->paginate(20),
        );
    }

    public function store(StoreAssetImportRequest $request, CreateAssetImport $create): JsonResponse
    {
        $import = $create->handle(
            $request->file('file'),
            AssetImportEntityMode::from($request->string('entity_mode')->value()),
            $request->user(),
            $request,
        );

        return (new AssetImportResource($import->load(['creator', 'confirmer'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $import): AssetImportResource
    {
        $import = $this->resolveImport($import);
        $this->authorize('view', $import);

        return new AssetImportResource($import->load(['creator', 'confirmer']));
    }

    /**
     * One column's distinct values and their proposed meanings, for a target
     * the administrator just chose on the mapping screen (before saving).
     */
    public function values(Request $request, string $import, AssetImportValues $values): JsonResponse
    {
        $import = $this->resolveImport($import);
        $this->authorize('view', $import);
        $columns = array_map(fn (array $c): int => (int) $c['index'], $import->headers ?? []);
        $validated = $request->validate([
            'column' => ['required', 'integer', Rule::in($columns)],
            'target' => ['required', 'string', Rule::in(ImportFieldDictionary::keys())],
        ]);

        return response()->json(['data' => $values->meaningTables($import, [(string) $validated['column'] => $validated['target']])]);
    }

    public function updateSource(UpdateAssetImportSourceRequest $request, string $import, UpdateAssetImportSource $action): AssetImportResource
    {
        $import = $this->resolveImport($import);

        return new AssetImportResource($action->handle($import, $request->options())->load(['creator', 'confirmer']));
    }

    public function updateMapping(UpdateAssetImportMappingRequest $request, string $import, UpdateAssetImportMapping $action): AssetImportResource
    {
        $import = $this->resolveImport($import);
        /** @var array<string, string|null> $mapping */
        $mapping = array_map(fn ($v): ?string => is_string($v) ? $v : null, (array) $request->input('mapping', []));

        $updated = $action->handle(
            $import,
            array_combine(array_map('strval', array_keys($mapping)), array_values($mapping)),
            (array) $request->input('value_mappings', []),
            (array) $request->input('defaults', []),
            $request->filled('date_order') ? ImportDateFormat::from($request->string('date_order')->value()) : null,
        );

        return new AssetImportResource($updated->load(['creator', 'confirmer']));
    }

    public function confirm(ConfirmAssetImportRequest $request, string $import, ConfirmAssetImport $action): JsonResponse
    {
        $import = $this->resolveImport($import);
        $result = $action->handle(
            $import,
            $request->integer('revision'),
            [
                'pc_units' => $request->integer('expected.pc_units'),
                'assets' => $request->integer('expected.assets'),
                'catalog_entries' => $request->integer('expected.catalog_entries'),
            ],
            $request->user(),
            $request,
        );

        return (new AssetImportResource($result['import']->load(['creator', 'confirmer'])))->response()->setStatusCode(202);
    }

    /** Cancel a draft, or ask a running save to stop. */
    public function cancel(Request $request, string $import, CancelAssetImport $action): AssetImportResource
    {
        $import = $this->resolveImport($import);
        $this->authorize('manage', $import);

        return new AssetImportResource($action->handle($import, $request->user(), $request)->load(['creator', 'confirmer']));
    }

    public function retry(Request $request, string $import, RetryAssetImport $action): AssetImportResource
    {
        $import = $this->resolveImport($import);
        $this->authorize('manage', $import);

        return new AssetImportResource($action->handle($import, $request->user(), $request)->load(['creator', 'confirmer']));
    }

    /**
     * Where this import's records now appear (Phase 2.10 R7). Reading it
     * writes nothing.
     */
    public function review(Request $request, string $import, ImportReviewBuilder $builder): JsonResponse
    {
        $import = $this->resolveImport($import);
        $this->authorize('view', $import);

        return response()->json(['data' => $builder->build($import, $request->user())]);
    }

    /** "I've reviewed": the receipt, and only the receipt. */
    public function acknowledgeReview(Request $request, string $import, AcknowledgeImportReview $action, ImportReviewBuilder $builder): JsonResponse
    {
        $import = $this->resolveImport($import);
        $this->authorize('manage', $import);

        $import = $action->handle($import, $request->user());

        return response()->json(['data' => $builder->build($import, $request->user())['receipt']]);
    }

    /** The original file, as an attachment, audited on every download. */
    public function file(Request $request, string $import, AuditLogger $audit): StreamedResponse|JsonResponse
    {
        $import = $this->resolveImport($import);
        $this->authorize('view', $import);

        if (! $import->hasFile() || ! Storage::disk($import->disk)->exists((string) $import->path)) {
            return response()->json(['message' => 'The original file is no longer kept.'], 410);
        }

        $audit->activity(
            ActivityAction::AssetImportFileDownloaded,
            actor: $request->user(),
            subject: $import,
            properties: ['import' => $import->uuid],
            request: $request,
            module: 'assets',
            description: 'Asset import file downloaded',
        );

        return AttachmentSecurity::stream(
            Storage::disk($import->disk),
            (string) $import->path,
            "asset-import-{$import->uuid}.{$import->extension}",
            $import->mime,
            AttachmentSecurity::PROFILE_SPREADSHEET,
        );
    }

    /** The blank template for a kind of file. */
    public function template(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', AssetImport::class);
        $mode = AssetImportEntityMode::tryFrom((string) $request->query('mode', 'pc_units')) ?? AssetImportEntityMode::PcUnits;

        return Excel::download(new AssetImportTemplateExport($mode), "sccit-import-template-{$mode->value}.xlsx");
    }

    /**
     * Rooms anywhere in the estate for a location decision — including rooms
     * not in use (marked, and assignable only with "assign anyway"); never
     * archived ones.
     */
    public function roomOptions(ImportRoomOptionsRequest $request): JsonResponse
    {
        $search = mb_strtolower(trim((string) $request->input('search', '')));
        $matcher = new ImportLocationMatcher;

        $rooms = Room::query()
            ->whereHas('floor', fn ($f) => $f->whereHas('building'))
            ->with('floor.building')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('lower(rooms.name) like ?', ['%'.addcslashes($search, '%_\\').'%'])
                ->orWhereRaw('lower(rooms.code) like ?', ['%'.addcslashes($search, '%_\\').'%'])))
            ->orderBy('name')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rooms->map(fn (Room $room): array => [
            'id' => $room->uuid,
            'label' => ImportLocationMatcher::roomLabel($room),
            'code' => $room->code,
            'selectable' => $matcher->selectable($room),
        ])->all()]);
    }

    /**
     * The import a uuid names, looked up only after the caller was authorised
     * — 404 for one that does not exist, never an existence oracle.
     */
    private function resolveImport(string $uuid): AssetImport
    {
        return AssetImport::query()->where('uuid', $uuid)->firstOrFail();
    }
}
