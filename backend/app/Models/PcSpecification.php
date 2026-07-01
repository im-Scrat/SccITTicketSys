<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PcSpecificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PcSpecification extends Model
{
    /** @use HasFactory<PcSpecificationFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /** @return BelongsTo<PcUnit, $this> */
    public function pcUnit(): BelongsTo
    {
        return $this->belongsTo(PcUnit::class);
    }
}
