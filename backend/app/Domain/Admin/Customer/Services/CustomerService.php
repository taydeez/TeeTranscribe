<?php

namespace App\Domain\Admin\Customer\Services;

use App\Domain\Admin\Customer\Contracts\CustomerRepositoryInterface;
use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use DateTimeImmutable;

final class CustomerService
{
    public function __construct(
        private CustomerRepositoryInterface $repository,
        private BillingRepositoryInterface $billing,
        private CreditService $credits,
    ) {}

    public function paginate(int $page = 1, int $perPage = 20, string $search = '', string $sort = 'newest'): array
    {
        return $this->repository->paginate($page, $perPage, trim($search), $sort);
    }

    public function show(int $id): array
    {
        return ['data' => $this->repository->find($id), 'balance' => $this->credits->balance($id)];
    }

    public function updateAccess(int $id, int $adminId, array $data): array
    {
        return $this->billing->transaction(function () use ($id, $adminId, $data): array {
            $this->repository->find($id, lock: true);
            $until = $data['action'] === 'suspend'
                ? (new DateTimeImmutable)->modify('+'.(int) $data['days'].' days')->format(DATE_ATOM) : null;
            $status = match ($data['action']) {
                'suspend' => 'suspended', 'block' => 'blocked', default => 'active'
            };
            $this->repository->setAccess($id, $status, $until, trim($data['reason']));
            $this->repository->recordAction($id, $adminId, $data['action'], trim($data['reason']), ['suspended_until' => $until]);

            return $this->show($id);
        });
    }

    public function adjustCredits(int $id, int $adminId, array $data): array
    {
        return $this->billing->transaction(function () use ($id, $adminId, $data): array {
            $this->repository->find($id, lock: true);
            $key = 'admin-credit:'.$id.':'.$data['client_key'];
            $metadata = ['action' => $data['action'], 'credit_units' => (int) $data['credit_units']];
            $existing = $this->repository->operation($key);
            if ($existing !== null) {
                if ($existing['metadata'] !== $metadata || $existing['reason'] !== trim($data['reason']) || $existing['admin_id'] !== $adminId) {
                    throw new BillingException('This request key was already used for a different adjustment.');
                }

                return $this->show($id);
            }
            $this->credits->balance($id);
            $wallet = $this->billing->lockWallet($id);
            $units = (int) $data['credit_units'];
            if ($data['action'] === 'remove' && $wallet->available < $units) {
                throw new BillingException('The customer does not have enough available credits. Reserved credits cannot be removed.', 422);
            }
            $wallet->available += $data['action'] === 'add' ? $units : -$units;
            $this->billing->saveWallet($wallet);
            $this->billing->appendEntry($wallet, $key, 'admin_'.$data['action'], $units, ['admin_id' => $adminId, 'reason' => trim($data['reason'])]);
            $this->repository->recordAction($id, $adminId, 'credits_'.$data['action'], trim($data['reason']), $metadata, $key);

            return $this->show($id);
        });
    }
}
