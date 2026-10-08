<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Translation\Contracts\TranslationGatewayInterface;
use App\Domain\Translation\Services\TranslationLanguages;

test('Nigerian language prioritization retains the complete global catalog', function () {
    $gateway = Mockery::mock(TranslationGatewayInterface::class);
    $gateway->shouldReceive('languages')->once()->andReturn([
        ['code' => 'ja', 'name' => 'Japanese'], ['code' => 'ig', 'name' => 'Igbo'], ['code' => 'en', 'name' => 'English'],
        ['code' => 'yo', 'name' => 'Yoruba'], ['code' => 'ha', 'name' => 'Hausa'], ['code' => 'ar', 'name' => 'Arabic'],
    ]);
    $items = (new TranslationLanguages($gateway))->all();
    expect(array_column($items, 'code'))->toBe(['yo', 'ig', 'ha', 'en', 'ar', 'ja']);
    expect(array_column(array_filter($items, fn ($item) => $item['nigerian']), 'code'))->toBe(['yo', 'ig', 'ha']);
    Mockery::close();
});

test('unsupported Pidgin cannot silently be treated as English', function () {
    $gateway = Mockery::mock(TranslationGatewayInterface::class);
    $gateway->shouldReceive('languages')->once()->andReturn([['code' => 'en', 'name' => 'English']]);
    expect(fn () => (new TranslationLanguages($gateway))->validate('pcm', 'en'))->toThrow(BillingException::class);
    Mockery::close();
});
