<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\GuestSessionController;
use App\Http\Controllers\Transcription\CreateTranscriptionController;
use App\Http\Controllers\Transcription\DeepgramWebhookController;
use App\Http\Controllers\Transcription\UpdateTranscriptionController;
use App\Http\Controllers\Upload\PresignAudioUploadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('/auth/admin/verify', [AuthController::class, 'verifyAdmin'])->middleware('throttle:auth');
    Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect']);
    Route::get('/auth/google/callback', [AuthController::class, 'googleCallback'])->middleware('throttle:auth');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/admin/user', AdminUserController::class)->middleware(['auth:sanctum', 'role:admin']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware('auth:sanctum');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::apiResource('folders', FolderController::class);
        Route::put('/folders/{folder}/transcriptions/{transcription}', [FolderController::class, 'attachTranscription']);
        Route::delete('/folders/{folder}/transcriptions/{transcription}', [FolderController::class, 'detachTranscription']);
        Route::patch('/transcriptions/{transcription}', UpdateTranscriptionController::class);
    });

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
