<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProcurementRequestItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementRequestItem extends Model
{
    /** @use HasFactory<ProcurementRequestItemFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'estimated_unit_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ProcurementRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class, 'procurement_request_id');
    }

    /** @return BelongsTo<HardwareModel, $this> */
    public function hardwareModel(): BelongsTo
    {
        return $this->belongsTo(HardwareModel::class);
    }
}
