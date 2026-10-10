<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Domain\Admin\Customer\Services\CustomerService;
use App\Http\Responses\Admin\CustomerResponse;
use Illuminate\Http\JsonResponse;

final class ShowCustomerController
{
    public function __invoke(int $customer, CustomerService $service, CustomerResponse $response): JsonResponse
    {
        return $response->listing($service->show($customer));
    }
}
