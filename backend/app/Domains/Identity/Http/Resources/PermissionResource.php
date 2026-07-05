<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Resources;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A permission definition for the permission-override matrix (SRS FR-USER-004/010).
 *
 * @mixin Permission
 */
class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'module' => $this->module,
            'description' => $this->description,
        ];
    }
}
