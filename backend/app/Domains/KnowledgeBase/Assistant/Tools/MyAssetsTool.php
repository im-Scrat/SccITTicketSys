<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Assistant\Tools;

use App\Domains\KnowledgeBase\Assistant\AssistantTool;
use App\Models\Asset;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Tools\Request;

/**
 * The equipment handed to this user as custodian — the same rows as
 * `GET /my/assets`. An administrator with `assets.view` may also look one
 * asset up by tag, as the register lets them.
 */
final class MyAssetsTool extends AssistantTool
{
    public function name(): string
    {
        return 'my_assets';
    }

    public function description(): string
    {
        return 'Equipment currently assigned to the current user (asset tag, name, status, room, warranty). '
            .'Give a tag to get one asset in detail. Administrators may look up any asset by tag.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tag' => $schema->string()->max(60)->description('An asset tag to look up, e.g. LAB2-PC-014. Omit to list everything assigned to the user.'),
        ];
    }

    protected function run(Request $request): string
    {
        $args = $request->validate(['tag' => ['nullable', 'string', 'max:60']]);
        $tag = trim((string) ($args['tag'] ?? ''));

        if ($tag !== '') {
            $asset = $this->lookupScope()->whereRaw('upper(asset_tag) = ?', [strtoupper($tag)])->first();

            if ($asset === null) {
                return 'No asset with that tag is available to you.';
            }

            return implode("\n", array_filter([
                "Asset tag: {$asset->asset_tag}",
                'Name: '.$asset->displayName(),
                'Status: '.$asset->status->label(),
                'Condition: '.$asset->condition->label(),
                $asset->hardwareModel !== null ? "Model: {$asset->hardwareModel->model_name}" : null,
                $asset->currentRoom !== null ? "Room: {$asset->currentRoom->name}" : 'Room: not placed',
                $asset->assigned_technician_id === $this->user->getKey() ? 'Custodian: you' : ($asset->assigned_technician_id !== null ? 'Custodian: a staff member' : 'Custodian: nobody'),
                $asset->warranty_expiration !== null ? 'Warranty until: '.$asset->warranty_expiration->toDateString().($asset->underWarranty() ? ' (in warranty)' : ' (expired)') : 'Warranty: unknown',
                $asset->purchase_date !== null ? 'Purchased: '.$asset->purchase_date->toDateString() : null,
                $asset->notes !== null ? 'Notes: '.$this->excerpt($asset->notes, 400) : null,
            ]));
        }

        $assets = $this->custody()->with(['currentRoom:id,name', 'hardwareModel:id,model_name'])->orderBy('asset_tag')->limit(25)->get();
        $total = $this->custody()->count();

        if ($assets->isEmpty()) {
            return 'No equipment is currently assigned to you.';
        }

        $lines = [sprintf('Equipment assigned to you: %d.', $total)];

        foreach ($assets as $asset) {
            $lines[] = sprintf(
                '- %s · %s · %s · %s',
                $asset->asset_tag,
                $asset->displayName(),
                $asset->status->label(),
                $asset->currentRoom !== null ? $asset->currentRoom->name : 'no room',
            );
        }

        if ($total > $assets->count()) {
            $lines[] = sprintf('(%d more not listed — see My assets.)', $total - $assets->count());
        }

        return implode("\n", $lines);
    }

    /** @return Builder<Asset> */
    private function custody(): Builder
    {
        // The same predicate as AssetController::custodyOf — custody, not room.
        return Asset::query()->where('assigned_technician_id', $this->user->getKey());
    }

    /** @return Builder<Asset> */
    private function lookupScope(): Builder
    {
        if ($this->isAdministrator() && $this->user->hasPermissionTo('assets.view')) {
            return Asset::query()->with(['currentRoom', 'hardwareModel']);
        }

        return $this->custody()->with(['currentRoom', 'hardwareModel']);
    }
}
