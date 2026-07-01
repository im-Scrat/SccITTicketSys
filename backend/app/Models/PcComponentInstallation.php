<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallationStatus;
use Database\Factories\PcComponentInstallationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
