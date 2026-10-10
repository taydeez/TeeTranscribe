<?php

use App\Domain\Admin\Customer\Contracts\CustomerRepositoryInterface;
use App\Domain\Admin\Customer\Services\CustomerService;
use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Entities\Wallet;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;

afterEach(fn () => Mockery::close());

test('customer credit removal cannot spend reserved units even when total wallet value is sufficient', function () {
    $customers = Mockery::mock(CustomerRepositoryInterface::class);
    $billing = Mockery::mock(BillingRepositoryInterface::class);
    $settings = Mockery::mock(BillingSettingsInterface::class);
    $wallet = new Wallet('wallet', 12, 100, 900);
    $customers->shouldReceive('find')->once()->with(12, true)->andReturn(['id' => 12]);
    $customers->shouldReceive('operation')->once()->andReturnNull();
    $customers->shouldNotReceive('recordAction');
    $billing->shouldReceive('transaction')->andReturnUsing(fn (Closure $operation) => $operation());
    $billing->shouldReceive('lockWallet')->twice()->with(12)->andReturn($wallet);
    $billing->shouldNotReceive('saveWallet');
    $billing->shouldNotReceive('appendEntry');
    $settings->shouldReceive('freeCreditUnits')->once()->andReturn(0);
    $service = new CustomerService($customers, $billing, new CreditService($billing, $settings));
    expect(fn () => $service->adjustCredits(12, 1, ['action' => 'remove', 'credit_units' => 101, 'client_key' => 'key', 'reason' => 'Correction']))
        ->toThrow(BillingException::class, 'Reserved credits cannot be removed.');
    expect($wallet->available)->toBe(100);
    expect($wallet->reserved)->toBe(900);
});
