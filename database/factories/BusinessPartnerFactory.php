<?php

namespace Database\Factories;

use App\Domain\Masters\Models\BusinessPartner;
use Illuminate\Database\Eloquent\Factories\Factory;

final class BusinessPartnerFactory extends Factory
{
    protected $model = BusinessPartner::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('BP-#####'), 'name' => fake()->company(),
            'is_supplier' => false, 'is_customer' => true, 'is_principal' => false,
            'credit_limit' => '0.00', 'payment_terms_days' => 0, 'on_hold' => false, 'active' => true,
        ];
    }
}
