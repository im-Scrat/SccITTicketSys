<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiModality;
use Database\Factories\AiModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiModel extends Model
{
    /** @use HasFactory<AiModelFactory> */
    use HasFactory;

    protected $table = 'ai_models';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'modality' => AiModality::class,
            'config' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /** @return HasMany<AiAnalysisLog, $this> */
    public function analysisLogs(): HasMany
    {
        return $this->hasMany(AiAnalysisLog::class);
    }
}
