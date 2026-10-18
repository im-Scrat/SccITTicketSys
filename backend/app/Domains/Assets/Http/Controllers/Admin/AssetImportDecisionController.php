<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers\Admin;

use App\Domains\Assets\Actions\DecideAssetImportCatalogEntry;
use App\Domains\Assets\Actions\ResolveAssetImportLocation;
use App\Domains\Assets\Http\Requests\DecideAssetImportCatalogRequest;
use App\Domains\Assets\Http\Requests\ResolveAssetImportLocationRequest;
use App\Domains\Assets\Http\Resources\AssetImportCatalogEntryResource;
use App\Domains\Assets\Http\Resources\AssetImportLocationResource;
use App\Domains\Assets\Http\Resources\AssetImportResource;
use App\Enums\ImportCatalogDecision;
use App\Enums\ImportLocationResolution;
use App\Http\Controllers\Controller;
use App\Models\AssetImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * An import's location and catalog decisions (Phase 2.10 R6, SDD DD-97, D5).
 * Both are looked up *inside* the import by their own uuid, so a uuid from
 * another import is a 404 — never a decision applied across imports.
 */
class AssetImportDecisionController extends Controller
{
    public function locations(Request $request, string $import): AnonymousResourceCollection
    {
        $import = $this->resolveImport($import);
        $this->authorize('view', $import);

        return AssetImportLocationResource::collection(
            $import->locations()->with('room.floor.building')
                ->orderByRaw("CASE resolution WHEN 'pending' THEN 0 ELSE 1 END")
                ->orderByDesc('row_count')
                ->get(),
        );
    }

    public function updateLocation(ResolveAssetImportLocationRequest $request, string $import, string $location, ResolveAssetImportLocation $action): JsonResponse
    {
        $import = $this->resolveImport($import);
        $row = $import->locations()->where('uuid', $location)->firstOrFail();

        $updated = $action->handle(
            $import,
            $row,
            ImportLocationResolution::from($request->string('resolution')->value()),
            $request->filled('room') ? $request->string('room')->value() : null,
            $request->boolean('assign_anyway'),
            $request->user(),
        );

        return response()->json([
            'data' => new AssetImportLocationResource($updated->load('room.floor.building')),
            'import' => new AssetImportResource($import->refresh()->load(['creator', 'confirmer'])),
        ]);
    }

    public function catalog(Request $request, string $import): AnonymousResourceCollection
    {
        $import = $this->resolveImport($import);
        $this->authorize('view', $import);

        return AssetImportCatalogEntryResource::collection(
            $import->catalogEntries()->with(['matchedModel.component.manufacturer', 'chosenModel.component.manufacturer'])
                ->orderByRaw("CASE decision WHEN 'pending' THEN 0 ELSE 1 END")
                ->orderByDesc('row_count')
                ->get(),
        );
    }

    public function updateCatalog(DecideAssetImportCatalogRequest $request, string $import, string $entry, DecideAssetImportCatalogEntry $action): JsonResponse
    {
        $import = $this->resolveImport($import);
        $row = $import->catalogEntries()->where('uuid', $entry)->firstOrFail();

        $updated = $action->handle(
            $import,
            $row,
            ImportCatalogDecision::from($request->string('decision')->value()),
            $request->filled('model') ? $request->integer('model') : null,
            $request->only(['manufacturer_name', 'model_name', 'model_number']),
            $request->user(),
        );

        return response()->json([
            'data' => new AssetImportCatalogEntryResource($updated->load(['matchedModel.component.manufacturer', 'chosenModel.component.manufacturer'])),
            'import' => new AssetImportResource($import->refresh()->load(['creator', 'confirmer'])),
        ]);
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
