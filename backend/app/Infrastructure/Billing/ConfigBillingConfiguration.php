<?php

namespace App\Infrastructure\Billing;

use App\Domain\Admin\Billing\Contracts\BillingConfigurationRepositoryInterface;
use App\Domain\Billing\Contracts\BillingConfigurationInterface;
use App\Domain\Billing\Services\CreditPackagePrice;

final readonly class ConfigBillingConfiguration implements BillingConfigurationInterface
{
    public function __construct(private BillingConfigurationRepositoryInterface $repository) {}

    public function current(): array
    {
        $configuration = $this->repository->find()['configuration'] ?? $this->defaults();
        $configuration['concurrency'] = array_replace($this->defaults()['concurrency'], $configuration['concurrency'] ?? []);
        $configuration['task_deadlines'] = array_replace_recursive($this->defaults()['task_deadlines'], $configuration['task_deadlines'] ?? []);
        $configuration['gateways']['bachs'] ??= $this->defaults()['gateways']['bachs'];

        foreach ($configuration['packages'] as &$package) {
            $package['price_usd'] ??= CreditPackagePrice::defaultUsdPrice($package['credits']);
        }
        unset($package, $configuration['fx']);

        return $configuration;
    }

    public function defaults(): array
    {
        $packages = [];
        foreach (config('billing.packages', []) as $code => $package) {
            $packages[] = ['code' => $code, 'name' => $package['name'], 'credits' => (string) $package['credits'],
                'price_ngn' => (string) ($package['price_ngn'] ?? $package['credits']),
                'price_usd' => $package['price_usd'] ?? CreditPackagePrice::defaultUsdPrice($package['credits']), 'enabled' => $package['enabled'] ?? true];
        }
        $activities = [];
        foreach (['transcription', 'translation', 'video_dubbing', 'audio_dubbing', 'subtitles', 'cleanup', 'summary'] as $activity) {
            $activities[$activity] = ['mode' => 'model', 'credits' => null];
        }
        $gateways = [];
        foreach (['paystack', 'flutterwave'] as $gateway) {
            $gateways[$gateway] = ['enabled' => (bool) config("payment.$gateway.enabled"),
                'currencies' => config("payment.$gateway.usd_enabled") ? ['NGN', 'USD'] : ['NGN']];
        }
        $gateways['bachs'] = ['enabled' => (bool) config('payment.bachs.enabled'), 'currencies' => ['USD']];

        return ['packages' => $packages, 'free_credits' => (string) config('billing.free_credits', '0'),
            'quote_minutes' => (int) config('billing.quote_minutes', 45), 'checkout_minutes' => (int) config('billing.checkout_minutes', 30),
            'processing_timeout_hours' => (int) config('billing.processing_timeout_hours', 26),
            'task_deadlines' => config('billing.task_deadlines'),
            'minimum_checkout' => ['NGN' => '50', 'USD' => '2'], 'enabled_currencies' => ['NGN', 'USD'],
            'gateways' => $gateways, 'activities' => $activities,
            'concurrency' => ['overall' => 3, 'transcription' => 2, 'translation' => 3, 'dubbing' => 1, 'transcript_tools' => 2]];
    }
}
