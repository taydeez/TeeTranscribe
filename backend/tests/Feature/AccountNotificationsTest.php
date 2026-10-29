<?php

use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Services\CreditService;
use App\Infrastructure\Persistence\Eloquent\Models\BillingQuote;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Jobs\SendAccountEmail;
use App\Models\User;
use App\Notifications\CreditMovementNotification;
use App\Notifications\NewAccountNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
beforeEach(function () {
    Notification::fake();
    Queue::fake();
    config(['billing.free_credits' => '0']);
    $this->user = User::factory()->create();
    $this->credits = app(CreditService::class);
    $this->credits->purchase($this->user->id, 10000, 'test-funding');
});

test('reservation and return emails follow the ledger once for each activity', function (string $activity, string $model) {
    $record = $model::factory()->create(['user_id' => $this->user->id]);
    $column = $activity.'_id';
    $quote = BillingQuote::create(['user_id' => $this->user->id, 'client_key' => (string) Str::uuid(),
        'activity' => $activity, 'provider' => 'test', 'model' => 'test', 'status' => 'submitted',
        'quantity' => 60000, 'credit_units' => 1234, 'source' => [], 'request_source' => [],
        'rate' => ['unit_length' => 60000], 'expires_at' => now()->addHour(), $column => $record->id]);
    DB::transaction(fn () => $this->credits->reserve($this->user->id, $quote->toArray(), $record->id));
    $this->artisan('outbox:publish')->assertSuccessful();
    Queue::assertPushed(SendAccountEmail::class, 1);
    $reserve = OutboxEvent::where('event_type', 'CreditsReserved')->sole();
    (new SendAccountEmail($reserve->id))->handle();
    (new SendAccountEmail($reserve->id))->handle();
    Notification::assertSentTo($this->user, CreditMovementNotification::class, fn ($mail) => $mail->kind === 'reserve'
        && $mail->units === 1234 && $mail->activity === $activity && $mail->availableUnits === 8766 && $mail->reservedUnits === 1234);
    match ($activity) {
        'translation' => $this->credits->releaseTranslation($record->id),
        'dubbing' => $this->credits->releaseDubbing($record->id),
        default => $this->credits->release($record->id),
    };
    match ($activity) {
        'translation' => $this->credits->releaseTranslation($record->id),
        'dubbing' => $this->credits->releaseDubbing($record->id),
        default => $this->credits->release($record->id),
    };
    $release = OutboxEvent::where('event_type', 'CreditsReturned')->sole();
    (new SendAccountEmail($release->id))->handle();
    (new SendAccountEmail($release->id))->handle();
    Notification::assertSentToTimes($this->user, CreditMovementNotification::class, 2);
    Notification::assertSentTo($this->user, CreditMovementNotification::class, fn ($mail) => $mail->kind === 'release'
        && $mail->availableUnits === 10000 && $mail->reservedUnits === 0);
    expect(UsageCharge::sole()->status)->toBe('released')->and(OutboxEvent::whereNull('published_at')->count())->toBe(0);
})->with([
    ['transcription', Transcription::class], ['translation', Translation::class], ['dubbing', Dubbing::class],
]);

test('rolled back ledger changes do not leave notification events', function () {
    $repository = app(BillingRepositoryInterface::class);
    expect(fn () => $repository->transaction(function () use ($repository) {
        $wallet = $repository->lockWallet($this->user->id);
        $repository->appendEntry($wallet, 'rolled-back', 'reserve', 100, ['transcription_id' => 'test']);
        throw new RuntimeException('Rollback');
    }))->toThrow(RuntimeException::class, 'Rollback');
    expect(CreditTransaction::where('event_key', 'rolled-back')->exists())->toBeFalse()
        ->and(OutboxEvent::where('event_type', 'CreditsReserved')->count())->toBe(0);
    Notification::assertNothingSent();
});

test('email delivery failures keep the outbox event available for retry', function () {
    Role::findOrCreate('user', 'web');
    $user = app(AuthRepositoryInterface::class)->register('Test User', 'mail@example.com', 'password1');
    $event = OutboxEvent::where('event_type', 'AccountRegistered')->sole();
    Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('Mail unavailable'));
    expect(fn () => (new SendAccountEmail($event->id))->handle())->toThrow(RuntimeException::class, 'Mail unavailable');
    expect($event->refresh()->published_at)->toBeNull();
    Notification::fake();
    (new SendAccountEmail($event->id))->handle();
    Notification::assertSentTo(User::findOrFail($user->id), NewAccountNotification::class);
    expect($event->refresh()->published_at)->not->toBeNull();
});
