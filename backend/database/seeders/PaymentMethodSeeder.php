<?php

namespace Database\Seeders;

use App\Infrastructure\Persistence\Eloquent\Models\Payment;
use App\Infrastructure\Persistence\Eloquent\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['paystack' => 'Paystack', 'flutterwave' => 'Flutterwave'] as $code => $name) {
            $method = PaymentMethod::firstOrCreate(['code' => $code], ['name' => $name, 'description' => 'Pay securely with '.$name.'.', 'is_active' => true, 'sort_order' => $code === 'paystack' ? 1 : 2, 'public_config' => ['currencies' => ['NGN', 'USD']]]);
            Payment::where('gateway', $code)->whereNull('payment_method_id')->update(['payment_method_id' => $method->id]);
        }
    }
}
