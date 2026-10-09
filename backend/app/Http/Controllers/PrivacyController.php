<?php

namespace App\Http\Controllers;

use App\Domain\Privacy\Services\PrivacyService;
use App\Domain\Privacy\Services\RetentionPolicy;
use App\Jobs\ProcessPrivacyDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PrivacyController extends Controller
{
    public function __construct(private readonly PrivacyService $privacy) {}

    public function settings(Request $request): JsonResponse
    {
        return response()->json($this->privacy->settings((int) $request->user()->getAuthIdentifier()));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $rules = ['retention' => ['required', 'array:'.implode(',', RetentionPolicy::categories()), 'min:1']];
        foreach (RetentionPolicy::categories() as $category) {
            $rules['retention.'.$category] = ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.RetentionPolicy::MAX_HOURS];
        }
        $input = $request->validate($rules);
        $changes = array_map(fn ($hours): ?int => $hours === null ? null : (int) $hours, $input['retention']);

        return response()->json($this->privacy->updateSettings((int) $request->user()->getAuthIdentifier(), $changes));
    }

    public function files(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return response()->json($this->privacy->files((int) $request->user()->getAuthIdentifier(), (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 20)));
    }

    public function deletions(Request $request): JsonResponse
    {
        return response()->json(['data' => array_map($this->data(...), $this->privacy->history((int) $request->user()->getAuthIdentifier()))]);
    }

    public function delete(Request $request): JsonResponse
    {
        $input = $request->validate([
            'resource_type' => ['required', 'in:upload,quote,transcription,translation,dubbing,folder'],
            'resource_id' => ['required', 'ulid'], 'scope' => ['required', 'in:project,source,generated'],
            'category' => ['sometimes', 'nullable', 'in:'.implode(',', RetentionPolicy::categories())],
        ]);
        $record = $this->privacy->delete((int) $request->user()->getAuthIdentifier(), $input['resource_type'], $input['resource_id'],
            $input['scope'], $input['category'] ?? null);
        $this->dispatch($record);

        return response()->json($this->data($record), 202);
    }

    public function show(Request $request, string $deletion): JsonResponse
    {
        return response()->json($this->data($this->privacy->find($deletion, (int) $request->user()->getAuthIdentifier())));
    }

    public function retry(Request $request, string $deletion): JsonResponse
    {
        $record = $this->privacy->retry($deletion, (int) $request->user()->getAuthIdentifier());
        $this->dispatch($record);

        return response()->json($this->data($record), 202);
    }

    private function data(array $record): array
    {
        return array_intersect_key($record, array_flip(['id', 'resourceType', 'resourceId', 'scope', 'category',
            'status', 'failureReason', 'createdAt', 'completedAt']));
    }

    private function dispatch(array $record): void
    {
        if ($record['status'] !== 'pending' && $record['status'] !== 'failed') {
            return;
        }
        try {
            ProcessPrivacyDeletion::dispatch($record['id'])->afterCommit();
        } catch (Throwable $exception) {
            Log::warning('Privacy cleanup dispatch deferred.', ['deletion_id' => $record['id'], 'exception_type' => $exception::class]);
        }
    }
}
