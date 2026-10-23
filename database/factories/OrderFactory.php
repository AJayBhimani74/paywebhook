<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => 'ORD-'.strtoupper(Str::random(8)),
            'customer_email' => fake()->safeEmail(),
            'amount' => fake()->numberBetween(10000, 500000),
            'currency' => 'INR',
            'status' => Order::PENDING,
        ];
    }
}
