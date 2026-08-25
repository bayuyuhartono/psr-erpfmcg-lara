<?php

namespace Database\Factories;

use App\Domain\Masters\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

final class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('SKU-######'), 'name' => fake()->words(3, true),
            'base_uom' => 'PCS', 'track_batch' => false, 'track_expiry' => false,
            'issue_method' => 'fefo', 'active' => true,
        ];
    }
}
