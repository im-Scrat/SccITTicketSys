<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockTransactionType;
use Database\Factories\StockTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransaction extends Model
{
    /** @use HasFactory<StockTransactionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'transaction_type' => StockTransactionType::class,
        ];
    }

    /** @return BelongsTo<Consumable, $this> */
    public function consumable(): BelongsTo
    {
        return $this->belongsTo(Consumable::class);
    }

    /** @return BelongsTo<User, $this> */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
