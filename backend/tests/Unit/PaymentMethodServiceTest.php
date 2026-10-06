<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Payment\Contracts\PaymentMethodRepositoryInterface;
use App\Domain\Payment\Contracts\PaymentSettingsInterface;
use App\Domain\Payment\Entities\PaymentMethod;
use App\Domain\Payment\Services\PaymentMethodService;

test('payment methods apply their configured currency restrictions', function () {
    $method = new PaymentMethod('method-id', 'Paystack', 'paystack', null, true, 1, ['currencies' => ['NGN']]);
    $repository = Mockery::mock(PaymentMethodRepositoryInterface::class);
    $repository->shouldReceive('active')->twice()->andReturn([$method]);
    $repository->shouldReceive('findActiveByCode')->with('paystack')->andReturn($method);
    $settings = Mockery::mock(PaymentSettingsInterface::class);
    $settings->shouldReceive('supports')->with('paystack', 'NGN')->andReturn(true);
    $service = new PaymentMethodService($repository, $settings);
    expect($service->available('NGN'))->toBe([$method])->and($service->available('USD'))->toBe([]);
    expect(fn () => $service->requireAvailable('paystack', 'USD'))->toThrow(BillingException::class);
});
