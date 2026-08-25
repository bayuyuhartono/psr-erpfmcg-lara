<?php

namespace Database\Factories;

use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

final class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('WH-###'), 'name' => fake()->city().' Warehouse', 'warehouse_type' => 'normal', 'active' => true];
    }
}
