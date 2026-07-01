<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    public function definition(): array
    {
        $module = fake()->randomElement(['tickets', 'assets', 'maintenance', 'inventory', 'ai', 'system']);
        $action = fake()->unique()->randomElement(['view', 'create', 'update', 'delete', 'assign', 'approve', 'export']);
        $name = "{$module}.{$action}.".fake()->unique()->numberBetween(1, 100000);

        return [
            'name' => $name,
            'slug' => Str::slug($name, '.'),
            'module' => $module,
            'description' => fake()->sentence(),
        ];
    }
}
