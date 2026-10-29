<?php

use App\Mail\PaymentInvoiceMail;
use App\Models\User;
use App\Notifications\CreditMovementNotification;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\NewAccountNotification;
use App\Notifications\PasswordResetNotification;
use App\Notifications\TranscriptionOutcomeNotification;
use Illuminate\Mail\Markdown;

test('notification action and header links use the frontend url in html and plain text', function (string $kind, string $path) {
    config(['app.url' => 'https://api.example.test', 'app.frontend_url' => 'https://frontend.example.test/']);
    $user = User::factory()->unverified()->make(['id' => 42]);
    if ($kind === 'verified_welcome') {
        $user->email_verified_at = now();
    }
    $notification = match ($kind) {
        'welcome', 'verified_welcome' => new NewAccountNotification,
        'verification' => new EmailVerificationNotification,
        'reset' => new PasswordResetNotification('reset-token'),
        'reserved' => new CreditMovementNotification('reserve', 100, 900, 100, 'transcription'),
        'returned' => new CreditMovementNotification('release', 100, 1000, 0, 'transcription'),
        'complete', 'failed' => new TranscriptionOutcomeNotification('Interview', $kind === 'complete', 'https://frontend.example.test'.$path),
    };

    $message = $notification->toMail($user);
    $html = (string) $message->render();
    $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());

    expect($message->actionUrl)->toStartWith('https://frontend.example.test'.$path);
    expect($html)->toContain('href="https://frontend.example.test"')->not->toContain('https://api.example.test');
    expect($text)->toContain('https://frontend.example.test'.$path)->not->toContain('https://api.example.test');
    preg_match_all('/href="([^"]+)"/', $html, $links);
    expect($links[1])->not->toBeEmpty();
    foreach ($links[1] as $link) {
        expect(html_entity_decode($link))->toStartWith('https://frontend.example.test');
    }
})->with([
    'welcome verification' => ['welcome', '/auth/verify-email?'],
    'verified welcome' => ['verified_welcome', '/dashboard'],
    'email verification' => ['verification', '/auth/verify-email?'],
    'password reset' => ['reset', '/auth/reset-password?'],
    'reserved credits' => ['reserved', '/dashboard/billing'],
    'returned credits' => ['returned', '/dashboard/billing'],
    'completed transcription folder' => ['complete', '/dashboard/transcriptions/01KTESTFOLDER'],
    'failed transcription folder' => ['failed', '/dashboard/transcriptions/01KTESTFOLDER'],
]);

test('payment invoice email links use the frontend url', function () {
    config(['app.url' => 'https://api.example.test', 'app.frontend_url' => 'https://frontend.example.test/']);
    $mail = new PaymentInvoiceMail([
        'package_name' => 'Starter', 'invoice_number' => 'INV-TEST', 'reference' => 'credits_test',
    ], 'invoice-pdf');

    $mail->assertSeeInHtml('href="https://frontend.example.test/dashboard/billing"', escape: false);
    $mail->assertDontSeeInHtml('https://api.example.test');
});
