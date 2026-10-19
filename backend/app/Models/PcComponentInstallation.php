<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallationStatus;
use Database\Factories\PcComponentInstallationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The authoritative record of which serialized asset is fitted inside which PC
 * (SRS FR-PC-004), as opposed to the editable spec snapshot.
 *
 * @property int $id
 * @property int $pc_unit_id
 * @property int $asset_id
 * @property int|null $installed_by
 * @property InstallationStatus $installation_status
 * @property Carbon|null $installation_date
 * @property Carbon|null $removal_date
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PcComponentInstallation extends Model
{
    /** @use HasFactory<PcComponentInstallationFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'installation_status' => InstallationStatus::class,
            'installation_date' => 'datetime',
            'removal_date' => 'datetime',
        ];
    }

    /** @return BelongsTo<PcUnit, $this> */
    public function pcUnit(): BelongsTo
    {
        return $this->belongsTo(PcUnit::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<User, $this> */
    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }
}
