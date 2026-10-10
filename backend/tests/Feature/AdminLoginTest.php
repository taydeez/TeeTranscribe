<?php

use App\Domain\Admin\TwoFactor\Contracts\AdminCodeMailerInterface;
use App\Models\AdminLoginCode;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

test('administrator can sign in through the real code mailer and verify the delivered code', function () {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['password' => 'password1'])->assignRole('admin');
    config(['mail.default' => 'array']);

    $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => 'password1'])
        ->assertOk()->assertJsonPath('requires_two_factor', true);

    $messages = Mail::mailer()->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);
    $message = $messages->first()->getOriginalMessage();
    expect($message->getTo()[0]->getAddress())->toBe($admin->email);
    preg_match('/login code is ([0-9]{6})/', $message->getTextBody(), $match);
    expect($match)->toHaveCount(2);
    $this->assertDatabaseCount('personal_access_tokens', 0);

    $this->postJson('/api/v1/auth/admin/verify', ['email' => $admin->email, 'code' => $match[1]])
        ->assertOk()->assertJsonStructure(['token']);
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('dedicated administrator login requires email two factor for both administrator roles', function (string $role) {
    Role::findOrCreate($role, 'web');
    $admin = User::factory()->create(['password' => 'password1'])->assignRole($role);
    $this->mock(AdminCodeMailerInterface::class)->shouldReceive('send')->once()->withArgs(fn ($email, $code) => $email === $admin->email && strlen($code) === 6);
    $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => 'password1'])
        ->assertOk()->assertJson(['requires_two_factor' => true, 'email' => $admin->email]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect(AdminLoginCode::where('user_id', $admin->id)->count())->toBe(1);
})->with(['admin', 'super_admin']);

test('dedicated administrator login rejects regular accounts without issuing tokens or codes', function () {
    Role::findOrCreate('user', 'web');
    $user = User::factory()->create(['password' => 'password1'])->assignRole('user');
    $this->mock(AdminCodeMailerInterface::class)->shouldNotReceive('send');
    $this->postJson('/api/v1/auth/admin/login', ['email' => $user->email, 'password' => 'password1'])->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials.');
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('admin_login_codes', 0);
});

test('dedicated administrator login rejects wrong passwords and rate limits retries', function () {
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create(['password' => 'password1'])->assignRole('admin');
    $this->mock(AdminCodeMailerInterface::class)->shouldNotReceive('send');
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/admin/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnauthorized();
    }
    $this->postJson('/api/v1/auth/admin/login', ['email' => $user->email, 'password' => 'password1'])->assertTooManyRequests();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('admin_login_codes', 0);
});
