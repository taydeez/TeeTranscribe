<?php

use App\Domain\Billing\Services\CreditPackagePrice;

test('promotional credits do not increase the amount charged', function () {
    $price = CreditPackagePrice::calculate(['name' => 'Bonus', 'credits' => '5500', 'price_ngn' => '5000'], 'NGN', null);
    expect($price['credit_units'])->toBe(550000)->and($price['amount_minor'])->toBe(500000);
});

test('USD purchases convert the package price and retain the credited amount', function () {
    $price = CreditPackagePrice::calculate(['name' => 'Bonus', 'credits' => '5500', 'price_ngn' => '5000'], 'USD', ['ngn_per_usd_micros' => 1500000000]);
    expect($price['credit_units'])->toBe(550000)->and($price['amount_minor'])->toBe(334)
        ->and($price['fx_ngn_per_usd_micros'])->toBe(1500000000);
});

test('existing packages without a separate price retain their original value', function () {
    $price = CreditPackagePrice::calculate(['name' => 'Starter', 'credits' => '5000'], 'NGN', null);
    expect($price['credit_units'])->toBe(500000)->and($price['amount_minor'])->toBe(500000);
});
