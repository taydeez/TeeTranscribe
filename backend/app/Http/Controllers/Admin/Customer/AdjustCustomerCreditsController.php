<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Domain\Admin\Customer\Services\CustomerService;
use App\Http\Requests\Admin\Customer\AdjustCustomerCreditsRequest;
use App\Http\Responses\Admin\CustomerResponse;
use Illuminate\Http\JsonResponse;

final class AdjustCustomerCreditsController
{
    public function __invoke(AdjustCustomerCreditsRequest $request, int $customer, CustomerService $service, CustomerResponse $response): JsonResponse
    {
        return $response->listing($service->adjustCredits($customer, (int) $request->user()->getAuthIdentifier(), $request->validated()));
    }
}
