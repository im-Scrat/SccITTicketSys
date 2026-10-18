<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers\Admin;

use App\Domains\Assets\Actions\UpdateAssetImportRows;
use App\Domains\Assets\Http\Requests\BulkAssetImportRowsRequest;
use App\Domains\Assets\Http\Requests\IndexAssetImportRowsRequest;
use App\Domains\Assets\Http\Requests\UpdateAssetImportRowRequest;
use App\Domains\Assets\Http\Resources\AssetImportResource;
use App\Domains\Assets\Http\Resources\AssetImportRowResource;
use App\Http\Controllers\Controller;
use App\Models\AssetImport;
use App\Models\AssetImportRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * An import's rows: list, correct, acknowledge, exclude (Phase 2.10 R6).
 * Rows are addressed by their row number inside the import, never by id.
 */
class AssetImportRowController extends Controller
{
    public function index(IndexAssetImportRowsRequest $request, string $import): AnonymousResourceCollection
    {
        $import = $this->resolveImport($import);
        $search = mb_strtolower(trim((string) $request->input('search', '')));

        $rows = $import->rows()
            ->with(['location.room.floor.building', 'catalogEntry', 'createdPcUnit:id,uuid,unit_code,pc_name', 'createdAsset:id,uuid,asset_tag'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()))
            ->when($request->filled('outcome'), fn ($q) => $q->where('outcome', $request->string('outcome')->value()))
            ->when($request->filled('type'), fn ($q) => $q->where('entity_type', $request->string('type')->value()))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('search_text', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('row_number', ctype_digit($search) ? (int) $search : -1)))
            ->orderBy('row_number')
            ->paginate($request->integer('per_page', 25));

        $rows->getCollection()->each(fn (AssetImportRow $row) => $row->setRelation('import', $import));

        return AssetImportRowResource::collection($rows);
    }

    public function update(UpdateAssetImportRowRequest $request, string $import, int $rowNumber, UpdateAssetImportRows $action): JsonResponse
    {
        $import = $this->resolveImport($import);
        $row = $import->rows()->where('row_number', $rowNumber)->firstOrFail();

        $updated = $action->update(
            $import,
            $row,
            $request->has('corrections') ? (array) $request->input('corrections') : null,
            $request->has('acknowledge') ? array_values((array) $request->input('acknowledge')) : null,
            $request->has('exclude') ? $request->boolean('exclude') : null,
            $request->user(),
        );
        $updated->load(['location.room.floor.building', 'catalogEntry'])->setRelation('import', $import->refresh());

        return response()->json([
            'data' => new AssetImportRowResource($updated),
            'import' => new AssetImportResource($import->load(['creator', 'confirmer'])),
        ]);
    }

    public function bulk(BulkAssetImportRowsRequest $request, string $import, UpdateAssetImportRows $action): JsonResponse
    {
        $import = $this->resolveImport($import);
        $count = $action->bulk($import, array_map('intval', (array) $request->input('rows')), $request->boolean('exclude'));

        return response()->json([
            'updated' => $count,
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
