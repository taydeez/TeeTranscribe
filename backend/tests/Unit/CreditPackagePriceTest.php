<?php

use App\Domain\Billing\Services\CreditPackagePrice;

test('promotional credits do not increase the amount charged', function () {
    $price = CreditPackagePrice::calculate(['name' => 'Bonus', 'credits' => '5500', 'price_ngn' => '5000'], 'NGN');
    expect($price['credit_units'])->toBe(550000)->and($price['amount_minor'])->toBe(500000);
});

test('USD purchases use the explicit price independently of credited amount', function () {
    $price = CreditPackagePrice::calculate(['name' => 'Bonus', 'credits' => '5500', 'price_usd' => '5.00'], 'USD');
    expect($price['credit_units'])->toBe(550000)->and($price['amount_minor'])->toBe(500)
        ->and($price['fx_ngn_per_usd_micros'])->toBeNull();
});

test('existing packages without a separate price retain their original value', function () {
    $price = CreditPackagePrice::calculate(['name' => 'Starter', 'credits' => '5000'], 'NGN');
    expect($price['credit_units'])->toBe(500000)->and($price['amount_minor'])->toBe(500000);
});
