<?php

use App\Models\AdminLoginCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

test('profile editing changes only the signed in users name', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $originalPassword = $user->password;
    $this->withToken($user->createToken('web')->plainTextToken)
        ->patchJson('/api/v1/auth/profile', ['name' => 'My Organization', 'user_id' => $other->id, 'email' => 'attacker@example.com'])
        ->assertOk();
    expect($user->refresh()->name)->toBe('My Organization')->and($user->email)->not->toBe('attacker@example.com')
        ->and($user->password)->toBe($originalPassword)->and($other->refresh()->name)->not->toBe('My Organization');
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('name', 'My Organization');
});

test('changing a password requires the current password and leaves sessions intact on failure', function () {
    $user = User::factory()->create(['password' => 'oldPassword1']);
    $token = $user->createToken('web')->plainTextToken;
    $this->withToken($token)->postJson('/api/v1/auth/change-password', [
        'current_password' => 'incorrect', 'password' => 'newPassword1', 'password_confirmation' => 'newPassword1',
    ])->assertUnprocessable()->assertJsonPath('message', 'Your current password is incorrect.');
    expect(Hash::check('oldPassword1', $user->refresh()->password))->toBeTrue()->and($user->tokens()->count())->toBe(1);
});

test('changing a password revokes all sessions reset links and admin codes for only the signed in account', function () {
    $user = User::factory()->create(['password' => 'oldPassword1']);
    $other = User::factory()->create(['password' => 'otherPassword1']);
    $other->createToken('other');
    $token = $user->createToken('current')->plainTextToken;
    $user->createToken('other-device');
    $resetToken = Password::createToken($user);
    AdminLoginCode::query()->create(['user_id' => $user->id, 'code_hash' => hash('sha256', '123456'), 'expires_at' => now()->addMinutes(10)]);
    $this->withToken($token)->postJson('/api/v1/auth/change-password', [
        'current_password' => 'oldPassword1', 'password' => 'newPassword1', 'password_confirmation' => 'newPassword1',
        'user_id' => $other->id,
    ])->assertOk();
    expect(Hash::check('newPassword1', $user->refresh()->password))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()->and($user->tokens()->count())->toBe(0)
        ->and(Password::tokenExists($user, $resetToken))->toBeFalse()
        ->and(AdminLoginCode::sole()->consumed_at)->not->toBeNull()
        ->and($other->tokens()->count())->toBe(1)
        ->and(Hash::check('otherPassword1', $other->refresh()->password))->toBeTrue();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/user')->assertUnauthorized();
});

test('settings changes require authentication and email verification', function () {
    $this->patchJson('/api/v1/auth/profile', ['name' => 'New Name'])->assertUnauthorized();
    $this->postJson('/api/v1/auth/change-password', [])->assertUnauthorized();
    $user = User::factory()->unverified()->create();
    $this->withToken($user->createToken('web')->plainTextToken)->patchJson('/api/v1/auth/profile', ['name' => 'New Name'])->assertForbidden();
    $this->postJson('/api/v1/auth/change-password', [])->assertForbidden();
});

test('password guessing is rate limited per account', function () {
    $user = User::factory()->create(['password' => 'oldPassword1']);
    $this->withToken($user->createToken('web')->plainTextToken);
    $body = ['current_password' => 'incorrect', 'password' => 'newPassword1', 'password_confirmation' => 'newPassword1'];
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/change-password', $body)->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/change-password', $body)->assertTooManyRequests();
});
