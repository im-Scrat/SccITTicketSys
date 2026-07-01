<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ManufacturerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Manufacturer extends Model
{
    /** @use HasFactory<ManufacturerFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    /** @return HasMany<HardwareComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(HardwareComponent::class);
    }
}
