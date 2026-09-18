<?php

use App\Http\Controllers\GuestSessionController;
use App\Http\Controllers\Transcription\CreateTranscriptionController;
use App\Http\Controllers\Transcription\DeepgramWebhookController;
use App\Http\Controllers\Upload\PresignAudioUploadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware('auth:sanctum');

    Route::post('/transcribe', CreateTranscriptionController::class);
    Route::post('/guest-sessions', GuestSessionController::class);
    Route::post('/uploads/presign', PresignAudioUploadController::class);

    // Deepgram Callback
    Route::post(
        '/webhooks/deepgram/{transcription}',
        DeepgramWebhookController::class
    )->middleware('signed:relative')
        ->name('deepgram.callback');

});
