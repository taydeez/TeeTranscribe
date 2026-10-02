<?php

use App\Models\AdminLoginCode;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

test('registers a user and returns a sanctum token', function () {
    Role::findOrCreate('user', 'web');
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Acme Organization', 'email' => 'owner@example.com',
        'password' => 'password1', 'password_confirmation' => 'password1',
    ])->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'roles']]);

    expect($response->json('token'))->not->toBeEmpty();
    expect(User::query()->where('email', 'owner@example.com')->firstOrFail()->hasRole('user'))->toBeTrue();
});

test('logs in a regular user and rejects an incorrect password', function () {
    Role::findOrCreate('user', 'web');
    User::factory()->create(['email' => 'user@example.com', 'password' => 'password1'])->assignRole('user');

    $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'wrong'])
        ->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com', 'password' => 'password1'])
        ->assertOk()->assertJsonStructure(['token', 'user']);
});

test('a login token authenticates the user and protected folder requests', function () {
    Role::findOrCreate('user', 'web');
    $user = User::factory()->create(['email' => 'member@example.com', 'password' => 'password1']);
    $user->assignRole('user');

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'member@example.com',
        'password' => 'password1',
    ])->assertOk()->json('token');

    $this->withToken($token)->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('email', 'member@example.com');
    $this->withToken($token)->getJson('/api/v1/folders')
        ->assertOk()
        ->assertJsonPath('data', []);
});

test('requires an email code before issuing an administrator token', function () {
    Mail::fake();
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['email' => 'admin@example.com', 'password' => 'password1']);
    $admin->assignRole('admin');

    $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password1'])
        ->assertOk()->assertJson(['requires_two_factor' => true, 'email' => $admin->email]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('admin_login_codes', 1);
});

test('consumes a valid administrator email code once', function () {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['email' => 'admin@example.com']);
    $admin->assignRole('admin');
    AdminLoginCode::query()->create(['user_id' => $admin->id, 'code_hash' => hash('sha256', '123456'), 'expires_at' => now()->addMinutes(10)]);

    $this->postJson('/api/v1/auth/admin/verify', ['email' => $admin->email, 'code' => '123456'])
        ->assertOk()->assertJsonStructure(['token']);
    $this->postJson('/api/v1/auth/admin/verify', ['email' => $admin->email, 'code' => '123456'])
        ->assertUnprocessable();
});

test('rate limits repeated login failures', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.com', 'password' => 'wrong'])
            ->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.com', 'password' => 'wrong'])
        ->assertTooManyRequests();
});

test('restricts administrator endpoints to the admin role', function () {
    Role::findOrCreate('user', 'web');
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create();
    $user->assignRole('user');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->withToken($user->createToken('web')->plainTextToken)->getJson('/api/v1/admin/user')->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($admin->createToken('admin')->plainTextToken)->getJson('/api/v1/admin/user')->assertOk();
});
