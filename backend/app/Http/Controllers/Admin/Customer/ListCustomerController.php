<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Domain\Admin\Customer\Services\CustomerService;
use App\Http\Requests\Admin\Customer\ListCustomerRequest;
use App\Http\Responses\Admin\CustomerResponse;
use Illuminate\Http\JsonResponse;

final class ListCustomerController
{
    public function __invoke(ListCustomerRequest $request, CustomerService $service, CustomerResponse $response): JsonResponse
    {
        return $response->listing($service->paginate(
            (int) $request->validated('page', 1),
            (int) $request->validated('per_page', 20),
            (string) $request->validated('search', ''),
            (string) $request->validated('sort', 'newest'),
        ));
    }
}
