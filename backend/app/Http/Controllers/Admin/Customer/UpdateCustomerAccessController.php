<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Domain\Admin\Customer\Services\CustomerService;
use App\Http\Requests\Admin\Customer\UpdateCustomerAccessRequest;
use App\Http\Responses\Admin\CustomerResponse;
use Illuminate\Http\JsonResponse;

final class UpdateCustomerAccessController
{
    public function __invoke(UpdateCustomerAccessRequest $request, int $customer, CustomerService $service, CustomerResponse $response): JsonResponse
    {
        return $response->listing($service->updateAccess($customer, (int) $request->user()->getAuthIdentifier(), $request->validated()));
    }
}
