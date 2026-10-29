<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditMath;
use App\Infrastructure\Billing\SafeAudioUrl;

test('represents fractional credits without floating point money', function () {
    expect(CreditMath::decimal('20.05'))->toBe(2005)
        ->and(CreditMath::decimal('0.01'))->toBe(1)
        ->and(CreditMath::decimal('1500.123456', 6))->toBe(1500123456);
});

test('rounds prorated activity charges up only to the nearest credit unit', function () {
    expect(CreditMath::prorate(2000, 90001, 60000))->toBe(3001)
        ->and(CreditMath::prorate(2000, 60000, 60000))->toBe(2000)
        ->and(CreditMath::prorate(1, 1, 60000))->toBe(1)
        ->and(CreditMath::prorate(1000, 1050, 1000))->toBe(1050);
});

test('converts NGN valued credits into whole USD cents', function () {
    expect(CreditMath::usdCents(500000, 1500000000))->toBe(334)
        ->and(CreditMath::usdCents(1500000, 1500000000))->toBe(1000);
});

test('rejects ambiguous or negative configured money', function (string $value) {
    expect(fn () => CreditMath::decimal($value))->toThrow(BillingException::class);
})->with(['1.001', '-1', '1e3', '']);

test('rejects excessive quantities instead of overflowing integers', function () {
    expect(fn () => CreditMath::prorate(1000000000, 1000000001, 1))->toThrow(BillingException::class);
});

test('blocks private media destinations before an HTTP request is made', function (string $url) {
    expect(fn () => (new SafeAudioUrl)->resolve($url))->toThrow(BillingException::class);
})->with(['http://127.0.0.1/audio.mp3', 'http://10.0.0.1/audio.mp3', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/audio.mp3', 'http://[::ffff:127.0.0.1]/audio.mp3']);
