<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PcSpecificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The 1:1 display snapshot of a PC's hardware (SRS FR-PC-003).
 *
 * Editable independently of `pc_component_installations`, which remains the
 * authoritative record of what is physically fitted (FR-PC-004).
 *
 * @property int $id
 * @property int $pc_unit_id
 * @property string|null $cpu
 * @property string|null $motherboard
 * @property string|null $ram
 * @property string|null $gpu
 * @property string|null $storage_primary
 * @property string|null $storage_secondary
 * @property string|null $power_supply
 * @property string|null $monitor
 * @property string|null $keyboard
 * @property string|null $mouse
 * @property string|null $operating_system
 * @property string|null $bios_version
 * @property string|null $network_adapter
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
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
