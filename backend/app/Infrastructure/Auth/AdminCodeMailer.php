<?php

namespace App\Infrastructure\Auth;

use App\Domain\Admin\TwoFactor\Contracts\AdminCodeMailerInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class AdminCodeMailer implements AdminCodeMailerInterface
{
    public function send(string $email, string $code): void
    {
        $startedAt = microtime(true);
        Log::info('Administrator login code delivery started.', ['mailer' => config('mail.default')]);

        try {
            Mail::raw("Your TeeTranscribe administrator login code is {$code}. It expires in 10 minutes.", fn ($mail) => $mail->to($email)->subject('Administrator login code'));
        } catch (Throwable $exception) {
            Log::warning('Administrator login code delivery failed.', [
                'exception_type' => $exception::class,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            throw $exception;
        }

        Log::info('Administrator login code delivery completed.', [
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
