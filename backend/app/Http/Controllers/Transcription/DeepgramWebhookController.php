<?php

namespace App\Http\Controllers\Transcription;

use App\Http\Requests\Transcription\DeepgramWebhookRequest;
use App\Http\Responses\ApiResponse;
use App\Infrastructure\AI\Transcriber\DeepGram\DeepgramWebhookHandler;
use Illuminate\Http\Response;

final class DeepgramWebhookController
{
    public function __invoke(DeepgramWebhookRequest $request, string $transcription, DeepgramWebhookHandler $handler, ApiResponse $response): Response
    {
        $handler->handle($request->validated(), $transcription);

        return $response->noContent();
    }
}
