<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Http\Requests\Billing\ListBillingHistoryRequest;
use App\Http\Responses\Billing\BillingResponse;
use Illuminate\Http\JsonResponse;

final class ListBillingHistoryController
{
    public function __invoke(ListBillingHistoryRequest $request, string $type, BillingRepositoryInterface $repository, BillingResponse $response): JsonResponse
    {
        $data = $request->validated();

        return $response->json($repository->history((int) $request->user()->getAuthIdentifier(), $type, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 20)));
    }
}
