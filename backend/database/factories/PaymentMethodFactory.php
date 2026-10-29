<?php

namespace Database\Factories;

use App\Infrastructure\Persistence\Eloquent\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentMethod> */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return ['name' => 'Paystack', 'code' => 'paystack', 'description' => 'Pay securely with Paystack.', 'is_active' => true, 'sort_order' => 1, 'public_config' => ['currencies' => ['NGN', 'USD']]];
    }
}
