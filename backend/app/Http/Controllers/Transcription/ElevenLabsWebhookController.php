<?php

namespace App\Http\Controllers\Transcription;

use App\Http\Requests\Transcription\ElevenLabsWebhookRequest;
use App\Http\Responses\ApiResponse;
use App\Infrastructure\AI\Transcriber\ElevenLabs\ElevenLabsWebhookHandler;
use Illuminate\Http\Response;

final class ElevenLabsWebhookController
{
    public function __invoke(ElevenLabsWebhookRequest $request, ElevenLabsWebhookHandler $handler, ApiResponse $response): Response
    {
        $handler->handle($request->validated(), (string) $request->input('type'));

        return $response->noContent();
    }
}
