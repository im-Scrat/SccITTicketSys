<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiModality;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\AiModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $provider
 * @property string $model_identifier
 * @property string|null $version
 * @property AiModality $modality
 * @property int|null $embedding_dimensions
 * @property array<string, mixed>|null $config
 * @property bool $is_active
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $uuid
 */
class AiModel extends Model
{
    /** @use HasFactory<AiModelFactory> */
    use HasFactory, HasUuidRouteKey;

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
