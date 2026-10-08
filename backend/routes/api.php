<?php

use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Dubbing\DubbingController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\GuestSessionController;
use App\Http\Controllers\Payment\PaymentWebhookController;
use App\Http\Controllers\Transcription\CreateTranscriptionController;
use App\Http\Controllers\Transcription\DeepgramWebhookController;
use App\Http\Controllers\Transcription\RequestTranscriptionExportsController;
use App\Http\Controllers\Transcription\UpdateTranscriptionController;
use App\Http\Controllers\Translation\TranslationController;
use App\Http\Controllers\Upload\MultipartUploadController;
use App\Http\Controllers\Upload\PresignAudioUploadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('/auth/forgot-password', [AccountSecurityController::class, 'forgotPassword'])->middleware('throttle:password-reset');
    Route::post('/auth/reset-password', [AccountSecurityController::class, 'resetPassword'])->middleware('throttle:password-reset');
    Route::post('/auth/email/resend', [AccountSecurityController::class, 'resendVerification'])->middleware(['auth:sanctum', 'throttle:verification-email']);
    Route::get('/auth/email/verify/{id}/{hash}', [AccountSecurityController::class, 'verifyEmail'])
        ->whereNumber('id')->where('hash', '[a-f0-9]{40}')->middleware(['signed:relative', 'throttle:6,1'])->name('verification.verify');
    Route::post('/auth/admin/verify', [AuthController::class, 'verifyAdmin'])->middleware('throttle:auth');
    Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect']);
    Route::get('/auth/google/callback', [AuthController::class, 'googleCallback'])->middleware('throttle:auth');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/admin/user', AdminUserController::class)->middleware(['auth:sanctum', 'verified', 'role:admin']);
    Route::get('/user', function (Request $request) {
        return response()->json($request->user()->toArray() + ['email_verified' => $request->user()->hasVerifiedEmail()]);
    })->middleware('auth:sanctum');

    Route::middleware(['auth:sanctum', 'verified'])->group(function (): void {
        Route::patch('/auth/profile', [AccountSecurityController::class, 'updateProfile'])->middleware('throttle:billing');
        Route::post('/auth/change-password', [AccountSecurityController::class, 'changePassword'])->middleware('throttle:account-password');
        Route::prefix('dubbings')->middleware('throttle:billing')->group(function (): void {
            $controller = DubbingController::class;
            Route::get('/languages', [$controller, 'languages']);
            Route::post('/quotes', [$controller, 'quote'])->middleware('throttle:billing-quotes');
            Route::get('/quotes/{quote}', [$controller, 'showQuote']);
            Route::post('/', [$controller, 'store']);
            Route::get('/', [$controller, 'index']);
            Route::get('/{dubbing}', [$controller, 'show']);
            Route::post('/{dubbing}/retry', [$controller, 'retry'])->middleware('throttle:billing-quotes');
        });
        Route::prefix('translations')->group(function (): void {
            $controller = TranslationController::class;
            Route::get('/languages', [$controller, 'languages'])->middleware('throttle:billing');
            Route::post('/quotes', [$controller, 'quote'])->middleware('throttle:billing-quotes');
            Route::post('/', [$controller, 'store'])->middleware('throttle:billing');
            Route::get('/', [$controller, 'index'])->middleware('throttle:billing');
            Route::get('/{translation}', [$controller, 'show'])->middleware('throttle:billing');
            Route::patch('/{translation}', [$controller, 'update'])->middleware('throttle:translation-edits');
        });
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
        Route::patch('/transcriptions/{transcription}', UpdateTranscriptionController::class)->middleware('throttle:transcript-edits');
        Route::post('/transcriptions/{transcription}/exports', RequestTranscriptionExportsController::class)->middleware('throttle:transcript-edits');
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
