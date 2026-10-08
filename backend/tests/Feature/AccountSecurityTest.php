<?php

use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Auth\Contracts\SocialAuthGatewayInterface;
use App\Domain\Auth\Entities\SocialIdentity;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Jobs\SendAccountEmail;
use App\Models\AdminLoginCode;
use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\NewAccountNotification;
use App\Notifications\PasswordResetNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
beforeEach(function () {
    Notification::fake();
    Role::findOrCreate('user', 'web');
    config(['app.frontend_url' => 'https://app.example.com']);
});

test('password reset requests do not reveal account existence and throttle email delivery', function () {
    $user = User::factory()->create();
    $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->json();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.com'])->assertOk()->assertExactJson($response);
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->assertExactJson($response);
    Notification::assertSentToTimes($user, PasswordResetNotification::class, 1);
    $notification = Notification::sent($user, PasswordResetNotification::class)->first();
    expect(Hash::check($notification->token, DB::table('password_reset_tokens')->where('email', $user->email)->value('token')))->toBeTrue();
    $mail = $notification->toMail($user);
    expect($mail->actionUrl)->toStartWith('https://app.example.com/auth/reset-password?');
});

test('password reset revokes existing sessions and codes and tokens are single use', function () {
    $user = User::factory()->unverified()->create(['password' => 'oldPassword1']);
    $user->createToken('existing');
    AdminLoginCode::query()->create(['user_id' => $user->id, 'code_hash' => hash('sha256', '123456'), 'expires_at' => now()->addMinutes(10)]);
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    $notification = Notification::sent($user, PasswordResetNotification::class)->first();
    $body = ['email' => $user->email, 'token' => $notification->token, 'password' => 'newPassword1', 'password_confirmation' => 'newPassword1'];
    $this->postJson('/api/v1/auth/reset-password', $body)->assertOk();
    expect(Hash::check('newPassword1', $user->refresh()->password))->toBeTrue()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->tokens()->count())->toBe(0)
        ->and(AdminLoginCode::sole()->consumed_at)->not->toBeNull();
    $this->postJson('/api/v1/auth/reset-password', $body)->assertUnprocessable();
});

test('expired and incorrect reset tokens cannot change the password', function () {
    $user = User::factory()->create(['password' => 'oldPassword1']);
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    $token = Notification::sent($user, PasswordResetNotification::class)->first()->token;
    $body = ['email' => $user->email, 'token' => 'incorrect', 'password' => 'newPassword1', 'password_confirmation' => 'newPassword1'];
    $this->postJson('/api/v1/auth/reset-password', $body)->assertUnprocessable();
    $this->travel(61)->minutes();
    $this->postJson('/api/v1/auth/reset-password', array_replace($body, ['token' => $token]))->assertUnprocessable();
    expect(Hash::check('oldPassword1', $user->refresh()->password))->toBeTrue();
});

test('new local accounts receive a welcome email with a working signed verification link', function () {
    Event::fake([Verified::class]);
    $this->postJson('/api/v1/auth/register', ['name' => 'Test Account', 'email' => 'new@example.com',
        'password' => 'password1', 'password_confirmation' => 'password1'])->assertCreated()->assertJsonPath('user.emailVerified', false);
    $user = User::where('email', 'new@example.com')->sole();
    $event = OutboxEvent::where('event_type', 'AccountRegistered')->sole();
    (new SendAccountEmail($event->id))->handle();
    (new SendAccountEmail($event->id))->handle();
    Notification::assertSentToTimes($user, NewAccountNotification::class, 1);
    $mail = Notification::sent($user, NewAccountNotification::class)->first()->toMail($user);
    expect($mail->actionUrl)->toStartWith('https://app.example.com/auth/verify-email?');
    parse_str(parse_url($mail->actionUrl, PHP_URL_QUERY), $query);
    $path = '/api/v1/auth/email/verify/'.$query['id'].'/'.$query['hash'].'?'.http_build_query(['expires' => $query['expires'], 'signature' => $query['signature']]);
    $this->getJson($path)->assertOk();
    $this->getJson($path)->assertOk();
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatchedTimes(Verified::class, 1);
});

test('verification rejects expired and tampered links and links for a changed email', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)], absolute: false);
    $this->getJson(str_replace(sha1($user->email), str_repeat('a', 40), $url))->assertForbidden();
    $user->update(['email' => 'changed@example.com']);
    $this->getJson($url)->assertForbidden();
    $user->update(['email' => 'restored@example.com']);
    $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)], absolute: false);
    $this->getJson($expired)->assertForbidden();
    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

test('unverified accounts can sign in and resend verification but cannot use protected features', function () {
    $user = User::factory()->unverified()->create(['password' => 'password1']);
    $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password1'])->assertOk()->json('token');
    $this->withToken($token)->getJson('/api/v1/user')->assertOk()->assertJsonPath('email_verified_at', null);
    $this->getJson('/api/v1/folders')->assertForbidden();
    $this->postJson('/api/v1/transcribe', [])->assertForbidden();
    $this->postJson('/api/v1/translations/quotes', [])->assertForbidden();
    $this->postJson('/api/v1/auth/email/resend')->assertOk();
    $this->postJson('/api/v1/auth/email/resend')->assertTooManyRequests();
    Notification::assertSentToTimes($user, EmailVerificationNotification::class, 1);
});

test('resending verification needs authentication and sends no email to a verified account', function () {
    $this->postJson('/api/v1/auth/email/resend')->assertUnauthorized();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/auth/email/resend')->assertOk();
    Notification::assertNothingSent();
});

test('new Google accounts are verified automatically and welcomed once', function () {
    $this->mock(SocialAuthGatewayInterface::class, function ($mock) {
        $mock->shouldReceive('identityFromCallback')->twice()->andReturn(new SocialIdentity('google-123', 'Google User', 'google@example.com'));
    });
    $this->get('/api/v1/auth/google/callback')->assertRedirect();
    $this->get('/api/v1/auth/google/callback')->assertRedirect();
    $user = User::where('email', 'google@example.com')->sole();
    expect($user->hasVerifiedEmail())->toBeTrue()->and(OutboxEvent::where('event_type', 'AccountRegistered')->count())->toBe(1);
    (new SendAccountEmail(OutboxEvent::sole()->id))->handle();
    $mail = Notification::sent($user, NewAccountNotification::class)->first()->toMail($user);
    expect($mail->actionUrl)->toBe('https://app.example.com/dashboard');
    Notification::assertNotSentTo($user, EmailVerificationNotification::class);
});

test('a verified Google identity verifies an existing local account without another welcome', function () {
    $user = User::factory()->unverified()->create(['email' => 'linked@example.com']);
    $result = app(AuthRepositoryInterface::class)->upsertGoogle('google-linked', $user->name, $user->email);
    expect($result->emailVerified)->toBeTrue()->and($user->refresh()->hasVerifiedEmail())->toBeTrue()
        ->and(OutboxEvent::where('event_type', 'AccountRegistered')->count())->toBe(0);
});

test('password recovery requests have a separate rate limit', function () {
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.com'])->assertOk();
    }
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.com'])->assertTooManyRequests();
});

test('existing social accounts do not need a separate verification email', function () {
    $user = User::factory()->unverified()->create(['google_id' => 'existing-google-account']);
    Sanctum::actingAs($user);
    $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('email_verified', true);
    $this->getJson('/api/v1/folders')->assertOk();
    $this->postJson('/api/v1/auth/email/resend')->assertOk();
    Notification::assertNothingSent();
});
