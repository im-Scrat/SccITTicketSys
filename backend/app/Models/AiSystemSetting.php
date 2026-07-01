<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiSystemSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSystemSetting extends Model
{
    /** @use HasFactory<AiSystemSettingFactory> */
    use HasFactory;

    protected $table = 'ai_system_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidence_threshold' => 'decimal:4',
            'enable_predictions' => 'boolean',
            'enable_learning' => 'boolean',
            'auto_generate_articles' => 'boolean',
        ];
    }

    /** @return BelongsTo<AiModel, $this> */
    public function activeModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'active_model_id');
    }

    /** @return BelongsTo<AiModel, $this> */
    public function embeddingModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'embedding_model_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
