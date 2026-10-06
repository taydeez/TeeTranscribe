<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\GuestSessionController;
use App\Http\Controllers\Payment\PaymentWebhookController;
use App\Http\Controllers\Transcription\CreateTranscriptionController;
use App\Http\Controllers\Transcription\DeepgramWebhookController;
use App\Http\Controllers\Transcription\UpdateTranscriptionController;
use App\Http\Controllers\Upload\MultipartUploadController;
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
        Route::post('/transcribe', CreateTranscriptionController::class)->middleware('throttle:billing');
        Route::prefix('billing')->middleware('throttle:billing')->group(function (): void {
            $controller = BillingController::class;
            Route::get('/balance', [$controller, 'balance']);
            Route::get('/packages', [$controller, 'packages']);
            Route::get('/payment-methods', [$controller, 'paymentMethods']);
            Route::get('/history/{type}', [$controller, 'history']);
            Route::post('/quotes', [$controller, 'createQuote'])->middleware('throttle:billing-quotes');
            Route::get('/quotes/{quote}', [$controller, 'quote']);
            Route::post('/purchases', [$controller, 'purchase']);
            Route::post('/purchases/{payment}/checkout', [$controller, 'checkout']);
            Route::post('/payments/verify', [$controller, 'verify']);
            Route::get('/payments/{payment}/invoice', [$controller, 'invoice']);
        });
        Route::prefix('uploads/multipart')->middleware('throttle:uploads')->group(function (): void {
            Route::post('/', [MultipartUploadController::class, 'start']);
            foreach (['status', 'part', 'complete', 'abort'] as $action) {
                Route::post('/{upload}/'.$action, [MultipartUploadController::class, $action]);
            }
        });
        Route::apiResource('folders', FolderController::class);
        Route::put('/folders/{folder}/transcriptions/{transcription}', [FolderController::class, 'attachTranscription']);
        Route::delete('/folders/{folder}/transcriptions/{transcription}', [FolderController::class, 'detachTranscription']);
        Route::patch('/transcriptions/{transcription}', UpdateTranscriptionController::class);
    });

    Route::post('/webhooks/{provider}', PaymentWebhookController::class)->whereIn('provider', ['paystack', 'flutterwave']);
    Route::post('/guest-sessions', GuestSessionController::class);
    Route::post('/uploads/presign', PresignAudioUploadController::class);

    // Deepgram Callback
    Route::post(
        '/webhooks/deepgram/{transcription}',
        DeepgramWebhookController::class
    )->middleware('signed:relative')
        ->name('deepgram.callback');

});
