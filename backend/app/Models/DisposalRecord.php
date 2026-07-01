<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisposalMethod;
use Database\Factories\DisposalRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisposalRecord extends Model
{
    /** @use HasFactory<DisposalRecordFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'disposal_method' => DisposalMethod::class,
            'disposal_date' => 'datetime',
            'salvage_value' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
