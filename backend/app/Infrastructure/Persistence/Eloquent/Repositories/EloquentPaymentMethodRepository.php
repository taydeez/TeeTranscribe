<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Payment\Contracts\PaymentMethodRepositoryInterface;
use App\Domain\Payment\Entities\PaymentMethod as PaymentMethodEntity;
use App\Infrastructure\Persistence\Eloquent\Models\PaymentMethod;

final class EloquentPaymentMethodRepository implements PaymentMethodRepositoryInterface
{
    public function active(): array
    {
        return PaymentMethod::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()->map(fn (PaymentMethod $model): PaymentMethodEntity => $this->map($model))->all();
    }

    public function findActiveByCode(string $code): ?PaymentMethodEntity
    {
        $model = PaymentMethod::where('is_active', true)->where('code', $code)->first();

        return $model ? $this->map($model) : null;
    }

    private function map(PaymentMethod $model): PaymentMethodEntity
    {
        return new PaymentMethodEntity($model->id, $model->name, $model->code, $model->description, $model->is_active, $model->sort_order, $model->public_config ?? []);
    }
}
