<?php

namespace App\Http\Controllers\Upload;

use App\Domain\Upload\Entities\UploadSession;
use App\Domain\Upload\Services\MultipartUploadService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Upload\StartMultipartUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MultipartUploadController extends Controller
{
    public function __construct(private readonly MultipartUploadService $service) {}

    public function start(StartMultipartUploadRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['size'] = (int) $data['size'];
        $session = $this->service->start((int) $request->user()->getAuthIdentifier(), $data);

        return $this->json($this->representation($session));
    }

    public function status(Request $request, string $upload): JsonResponse
    {
        [$session, $parts] = $this->service->inspect($upload, (int) $request->user()->getAuthIdentifier());

        return $this->json($this->representation($session) + ['parts' => $parts]);
    }

    public function part(Request $request, string $upload): JsonResponse
    {
        $data = $request->validate(['part_number' => ['required', 'integer', 'min:1']]);

        return $this->json($this->service->signPart($upload, (int) $request->user()->getAuthIdentifier(), (int) $data['part_number']));
    }

    public function complete(Request $request, string $upload): JsonResponse
    {
        return $this->json($this->service->complete($upload, (int) $request->user()->getAuthIdentifier()));
    }

    public function abort(Request $request, string $upload): JsonResponse
    {
        $this->service->abort($upload, (int) $request->user()->getAuthIdentifier());

        return $this->json(['status' => 'aborted']);
    }

    private function representation(UploadSession $session): array
    {
        return [
            'id' => $session->id, 'status' => $session->status->value,
            'filename' => $session->filename, 'size' => $session->size,
            'part_size' => $session->partSize, 'part_count' => $session->partCount(),
            'expires_at' => $session->expiresAt->format(DATE_ATOM),
        ];
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store');
    }
}
