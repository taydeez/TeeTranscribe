<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\GoogleCallbackController;
use App\Http\Controllers\Auth\GoogleRedirectController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResendVerificationController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\ShowUserController;
use App\Http\Controllers\Auth\StoreGuestSessionController;
use App\Http\Controllers\Auth\UpdateProfileController;
use App\Http\Controllers\Auth\VerifyAdminLoginController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Billing\CheckoutPurchaseController;
use App\Http\Controllers\Billing\CreateUsageQuoteController;
use App\Http\Controllers\Billing\DownloadInvoiceController;
use App\Http\Controllers\Billing\ListBillingHistoryController;
use App\Http\Controllers\Billing\ListCreditPackagesController;
use App\Http\Controllers\Billing\ListPaymentMethodsController;
use App\Http\Controllers\Billing\ShowBalanceController;
use App\Http\Controllers\Billing\ShowUsageQuoteController;
use App\Http\Controllers\Billing\StorePurchaseController;
use App\Http\Controllers\Billing\VerifyPaymentController;
use App\Http\Controllers\Dubbing\CreateDubbingQuoteController;
use App\Http\Controllers\Dubbing\ListDubbingController;
use App\Http\Controllers\Dubbing\ListDubbingLanguagesController;
use App\Http\Controllers\Dubbing\RetryDubbingController;
use App\Http\Controllers\Dubbing\ShowDubbingController;
use App\Http\Controllers\Dubbing\ShowDubbingQuoteController;
use App\Http\Controllers\Dubbing\StoreDubbingController;
use App\Http\Controllers\Folder\AttachFolderTranscriptionController;
use App\Http\Controllers\Folder\DeleteFolderController;
use App\Http\Controllers\Folder\DetachFolderTranscriptionController;
use App\Http\Controllers\Folder\ListFolderController;
use App\Http\Controllers\Folder\ShowFolderController;
use App\Http\Controllers\Folder\StoreFolderController;
use App\Http\Controllers\Folder\UpdateFolderController;
use App\Http\Controllers\Payment\PaymentWebhookController;
use App\Http\Controllers\Privacy\ListPrivacyDeletionsController;
use App\Http\Controllers\Privacy\ListPrivacyFilesController;
use App\Http\Controllers\Privacy\RetryPrivacyDeletionController;
use App\Http\Controllers\Privacy\ShowPrivacyDeletionController;
use App\Http\Controllers\Privacy\ShowPrivacySettingsController;
use App\Http\Controllers\Privacy\StorePrivacyDeletionController;
use App\Http\Controllers\Privacy\UpdatePrivacySettingsController;
use App\Http\Controllers\Transcription\CreateTranscriptionController;
use App\Http\Controllers\Transcription\CreateTranscriptToolQuoteController;
use App\Http\Controllers\Transcription\DeepgramWebhookController;
use App\Http\Controllers\Transcription\ElevenLabsWebhookController;
use App\Http\Controllers\Transcription\ListTranscriptToolsController;
use App\Http\Controllers\Transcription\RequestTranscriptionExportsController;
use App\Http\Controllers\Transcription\ShowTranscriptToolController;
use App\Http\Controllers\Transcription\StoreTranscriptToolController;
use App\Http\Controllers\Transcription\UpdateTranscriptionController;
use App\Http\Controllers\Translation\CreateTranslationQuoteController;
use App\Http\Controllers\Translation\ListTranslationController;
use App\Http\Controllers\Translation\ListTranslationLanguagesController;
use App\Http\Controllers\Translation\ShowTranslationController;
use App\Http\Controllers\Translation\StoreTranslationController;
use App\Http\Controllers\Translation\UpdateTranslationController;
use App\Http\Controllers\Upload\AbortMultipartUploadController;
use App\Http\Controllers\Upload\CompleteMultipartUploadController;
use App\Http\Controllers\Upload\PresignAudioUploadController;
use App\Http\Controllers\Upload\ShowMultipartUploadController;
use App\Http\Controllers\Upload\SignMultipartUploadPartController;
use App\Http\Controllers\Upload\StartMultipartUploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', RegisterController::class)->middleware('throttle:auth');
    Route::post('/auth/login', LoginController::class)->middleware('throttle:auth');
    Route::post('/auth/forgot-password', ForgotPasswordController::class)->middleware('throttle:password-reset');
    Route::post('/auth/reset-password', ResetPasswordController::class)->middleware('throttle:password-reset');
    Route::post('/auth/email/resend', ResendVerificationController::class)->middleware(['auth:sanctum', 'throttle:verification-email']);
    Route::get('/auth/email/verify/{id}/{hash}', VerifyEmailController::class)
        ->whereNumber('id')->where('hash', '[a-f0-9]{40}')->middleware(['signed:relative', 'throttle:6,1'])->name('verification.verify');
    Route::post('/auth/admin/verify', VerifyAdminLoginController::class)->middleware('throttle:auth');
    Route::get('/auth/google/redirect', GoogleRedirectController::class);
    Route::get('/auth/google/callback', GoogleCallbackController::class)->middleware('throttle:auth');
    Route::post('/auth/logout', LogoutController::class)->middleware('auth:sanctum');
    Route::get('/admin/user', AdminUserController::class)->middleware(['auth:sanctum', 'verified', 'role:admin']);
    Route::get('/user', ShowUserController::class)->middleware('auth:sanctum');

    Route::middleware(['auth:sanctum', 'verified'])->group(function (): void {
        Route::prefix('privacy')->middleware('throttle:billing')->group(function (): void {
            Route::get('/settings', ShowPrivacySettingsController::class);
            Route::patch('/settings', UpdatePrivacySettingsController::class);
            Route::get('/files', ListPrivacyFilesController::class);
            Route::get('/deletions', ListPrivacyDeletionsController::class);
            Route::post('/deletions', StorePrivacyDeletionController::class);
            Route::get('/deletions/{deletion}', ShowPrivacyDeletionController::class);
            Route::post('/deletions/{deletion}/retry', RetryPrivacyDeletionController::class);
        });
        Route::patch('/auth/profile', UpdateProfileController::class)->middleware('throttle:billing');
        Route::post('/auth/change-password', ChangePasswordController::class)->middleware('throttle:account-password');
        Route::prefix('dubbings')->middleware('throttle:billing')->group(function (): void {
            Route::get('/languages', ListDubbingLanguagesController::class);
            Route::post('/quotes', CreateDubbingQuoteController::class)->middleware('throttle:billing-quotes');
            Route::get('/quotes/{quote}', ShowDubbingQuoteController::class);
            Route::post('/', StoreDubbingController::class);
            Route::get('/', ListDubbingController::class);
            Route::get('/{dubbing}', ShowDubbingController::class);
            Route::post('/{dubbing}/retry', RetryDubbingController::class)->middleware('throttle:billing-quotes');
        });
        Route::prefix('translations')->group(function (): void {
            Route::get('/languages', ListTranslationLanguagesController::class)->middleware('throttle:billing');
            Route::post('/quotes', CreateTranslationQuoteController::class)->middleware('throttle:billing-quotes');
            Route::post('/', StoreTranslationController::class)->middleware('throttle:billing');
            Route::get('/', ListTranslationController::class)->middleware('throttle:billing');
            Route::get('/{translation}', ShowTranslationController::class)->middleware('throttle:billing');
            Route::patch('/{translation}', UpdateTranslationController::class)->middleware('throttle:translation-edits');
        });
        Route::post('/transcribe', CreateTranscriptionController::class)->middleware('throttle:billing');
        Route::prefix('billing')->middleware('throttle:billing')->group(function (): void {
            Route::get('/balance', ShowBalanceController::class);
            Route::get('/packages', ListCreditPackagesController::class);
            Route::get('/payment-methods', ListPaymentMethodsController::class);
            Route::get('/history/{type}', ListBillingHistoryController::class);
            Route::post('/quotes', CreateUsageQuoteController::class)->middleware('throttle:billing-quotes');
            Route::get('/quotes/{quote}', ShowUsageQuoteController::class);
            Route::post('/purchases', StorePurchaseController::class);
            Route::post('/purchases/{payment}/checkout', CheckoutPurchaseController::class);
            Route::post('/payments/verify', VerifyPaymentController::class);
            Route::get('/payments/{payment}/invoice', DownloadInvoiceController::class);
        });
        Route::prefix('uploads/multipart')->middleware('throttle:uploads')->group(function (): void {
            Route::post('/', StartMultipartUploadController::class);
            Route::post('/{upload}/status', ShowMultipartUploadController::class);
            Route::post('/{upload}/part', SignMultipartUploadPartController::class);
            Route::post('/{upload}/complete', CompleteMultipartUploadController::class);
            Route::post('/{upload}/abort', AbortMultipartUploadController::class);
        });
        Route::get('/folders', ListFolderController::class)->name('folders.index');
        Route::post('/folders', StoreFolderController::class)->name('folders.store');
        Route::get('/folders/{folder}', ShowFolderController::class)->name('folders.show');
        Route::match(['PUT', 'PATCH'], '/folders/{folder}', UpdateFolderController::class)->name('folders.update');
        Route::delete('/folders/{folder}', DeleteFolderController::class)->name('folders.destroy');
        Route::put('/folders/{folder}/transcriptions/{transcription}', AttachFolderTranscriptionController::class);
        Route::delete('/folders/{folder}/transcriptions/{transcription}', DetachFolderTranscriptionController::class);
        Route::patch('/transcriptions/{transcription}', UpdateTranscriptionController::class)->middleware('throttle:transcript-edits');
        Route::post('/transcriptions/{transcription}/exports', RequestTranscriptionExportsController::class)->middleware('throttle:transcript-edits');
        Route::prefix('transcriptions/{transcription}/tools')->group(function (): void {
            Route::get('/', ListTranscriptToolsController::class)->middleware('throttle:billing');
            Route::post('/quotes', CreateTranscriptToolQuoteController::class)->middleware('throttle:billing-quotes');
            Route::post('/', StoreTranscriptToolController::class)->middleware('throttle:transcript-edits');
            Route::get('/{tool}', ShowTranscriptToolController::class)->middleware('throttle:billing');
        });
    });

    Route::post('/webhooks/{provider}', PaymentWebhookController::class)->whereIn('provider', ['paystack', 'flutterwave']);
    Route::post('/guest-sessions', StoreGuestSessionController::class);
    Route::post('/uploads/presign', PresignAudioUploadController::class);

    Route::post('/webhooks/elevenlabs/transcription', ElevenLabsWebhookController::class)->name('elevenlabs.transcription.callback');

    // Deepgram Callback
    Route::post(
        '/webhooks/deepgram/{transcription}',
        DeepgramWebhookController::class
    )->middleware('signed:relative')
        ->name('deepgram.callback');

});
