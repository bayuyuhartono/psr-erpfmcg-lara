<?php

namespace Database\Factories;

use App\Domain\Masters\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('BR-###'), 'name' => fake()->city().' Branch', 'active' => true];
    }
}
