<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StockTransactionType;
use App\Models\Consumable;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransaction>
 */
class StockTransactionFactory extends Factory
{
    protected $model = StockTransaction::class;

    public function definition(): array
    {
        return [
            'consumable_id' => Consumable::factory(),
            'transaction_type' => StockTransactionType::StockIn->value,
            'quantity' => fake()->numberBetween(1, 100),
            'performed_by' => User::factory(),
            'reference_number' => fake()->optional()->bothify('REF-#####'),
        ];
    }
}
