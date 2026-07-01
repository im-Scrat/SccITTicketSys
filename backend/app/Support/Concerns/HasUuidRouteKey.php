<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Public `uuid` identifier support:
 *  - auto-generates the uuid on create (so it is available immediately on the
 *    instance, not only after a reload from the DB default), and
 *  - binds route-model resolution to `uuid` instead of the bigint PK, so
 *    internal identifiers are never exposed in URLs/APIs.
 *
 * The migration keeps a `gen_random_uuid()` default as a safety net for rows
 * inserted outside Eloquent.
 */
trait HasUuidRouteKey
{
    protected static function bootHasUuidRouteKey(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
