<?php

use App\Domain\Admin\Auth\Services\AdminAccess;
use App\Domain\Admin\TwoFactor\Contracts\AdminCodeMailerInterface;
use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Auth\Contracts\SocialAuthGatewayInterface;
use App\Domain\Auth\Entities\SocialIdentity;
use App\Models\AdminLoginCode;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

function adminSecurityUser(string $role = 'super_admin'): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create(['password' => 'password1'])->assignRole($role);
}

function adminSecurityToken(User $user): string
{
    AdminLoginCode::query()->create(['user_id' => $user->id, 'code_hash' => hash('sha256', '123456'), 'expires_at' => now()->addMinutes(10)]);

    return test()->postJson('/api/v1/auth/admin/verify', ['email' => $user->email, 'code' => '123456'])->assertOk()->json('token');
}

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('seeds model action permissions idempotently without elevating ordinary users', function () {
    $user = User::factory()->create()->assignRole('user');
    $this->seed(PermissionsSeeder::class);
    expect(Permission::where('name', 'ViewAny_Role')->count())->toBe(1);
    expect(Role::findByName('super_admin', 'web')->hasPermissionTo('Create_Role'))->toBeTrue();
    expect($user->fresh()->hasRole('super_admin'))->toBeFalse();
    expect(Role::findByName('user', 'web')->permissions()->count())->toBe(0);
    $this->seed(UserSeeder::class);
    expect(User::where('email', 'admin@example.com')->firstOrFail()->hasRole('super_admin'))->toBeTrue();
    expect(User::where('email', 'user@example.com')->firstOrFail()->hasRole('super_admin'))->toBeFalse();
});

test('email verification is required for both administrator roles and sends a usable hashed code', function (string $role) {
    $user = adminSecurityUser($role);
    $code = null;
    $this->mock(AdminCodeMailerInterface::class)->shouldReceive('send')->once()->withArgs(function ($email, $value) use ($user, &$code) {
        $code = $value;

        return $email === $user->email && preg_match('/^\d{6}$/', $value);
    });
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password1'])->assertOk()->assertJsonPath('requires_two_factor', true);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect(AdminLoginCode::firstOrFail()->code_hash)->toBe(hash('sha256', $code))->not->toBe($code);
    $token = $this->postJson('/api/v1/auth/admin/verify', ['email' => $user->email, 'code' => $code])->assertOk()->json('token');
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/admin/user')->assertOk();
    $record = $user->tokens()->firstOrFail();
    expect($record->abilities)->toBe([AdminAccess::TOKEN_ABILITY]);
    expect($record->expires_at->diffInSeconds(now(), absolute: true))->toBeLessThanOrEqual(300);
})->with(['admin', 'super_admin']);

test('locks an email code after five wrong guesses and never accepts a used or expired code', function () {
    $user = adminSecurityUser();
    AdminLoginCode::create(['user_id' => $user->id, 'code_hash' => hash('sha256', '123456'), 'expires_at' => now()->addMinutes(10)]);
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/admin/verify', ['email' => $user->email, 'code' => '000000'])->assertUnprocessable();
    }
    expect(AdminLoginCode::firstOrFail()->attempts)->toBe(5);
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/admin/verify', ['email' => $user->email, 'code' => '123456'])->assertUnprocessable();
    AdminLoginCode::query()->delete();
    AdminLoginCode::create(['user_id' => $user->id, 'code_hash' => hash('sha256', '123456'), 'expires_at' => now()->subSecond()]);
    $this->postJson('/api/v1/auth/admin/verify', ['email' => $user->email, 'code' => '123456'])->assertUnprocessable();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('new codes invalidate earlier codes', function () {
    $user = adminSecurityUser();
    $codes = [];
    $this->mock(AdminCodeMailerInterface::class)->shouldReceive('send')->twice()->withArgs(function ($email, $code) use (&$codes) {
        $codes[] = $code;

        return true;
    });
    for ($i = 0; $i < 2; $i++) {
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password1'])->assertOk();
    }
    expect(AdminLoginCode::whereNull('consumed_at')->count())->toBe(1);
    $this->postJson('/api/v1/auth/admin/verify', ['email' => $user->email, 'code' => $codes[1]])->assertOk();
    $this->postJson('/api/v1/auth/admin/verify', ['email' => $user->email, 'code' => $codes[1]])->assertUnprocessable();
});

test('background API requests do not extend administrator inactivity timeout', function () {
    $user = adminSecurityUser();
    $token = adminSecurityToken($user);
    $this->travel(4)->minutes();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/admin/user')->assertOk();
    $this->travel(1)->minutes();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/user')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/admin/session/activity')->assertUnauthorized();
});

test('activity extends only the current verified administrator session', function () {
    $user = adminSecurityUser();
    $token = adminSecurityToken($user);
    $other = app(AuthRepositoryInterface::class)->issueToken($user->id, 'admin');
    $otherExpiry = $user->tokens()->latest('id')->firstOrFail()->expires_at->toIso8601String();
    $this->travel(4)->minutes();
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/admin/session/activity')->assertOk()->assertJsonStructure(['expires_at']);
    expect($user->tokens()->latest('id')->firstOrFail()->expires_at->toIso8601String())->toBe($otherExpiry);
    $this->travel(2)->minutes();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/admin/user')->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($other)->getJson('/api/v1/admin/user')->assertUnauthorized();
});

test('ordinary users do not get the administrator timeout or access even with role permissions', function () {
    $user = adminSecurityUser('user');
    $user->givePermissionTo('ViewAny_Role');
    $token = $user->createToken('web')->plainTextToken;
    $this->travel(6)->minutes();
    $this->withToken($token)->getJson('/api/v1/user')->assertOk()->assertJsonPath('is_admin', false);
    $this->getJson('/api/v1/admin/roles')->assertForbidden();
    $this->postJson('/api/v1/admin/session/activity')->assertForbidden();
});

test('existing non two factor admin tokens cannot bypass verification', function (string $name) {
    $user = adminSecurityUser();
    $token = $user->createToken($name)->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/admin/user')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/user')->assertUnauthorized();
})->with(['web', 'google', 'admin']);

test('google administrator login also requests an email code instead of issuing a token', function () {
    $user = adminSecurityUser();
    $this->mock(SocialAuthGatewayInterface::class)->shouldReceive('identityFromCallback')->once()->andReturn(new SocialIdentity('google-admin', $user->name, $user->email));
    $this->mock(AdminCodeMailerInterface::class)->shouldReceive('send')->once();
    $this->get('/api/v1/auth/google/callback')->assertRedirectContains('/taydeez/login?verify=1&email=');
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('admin_login_codes', 1);
});

test('requires the specific role permission even after administrator two factor verification', function () {
    $admin = adminSecurityUser('admin');
    $token = adminSecurityToken($admin);
    $this->withToken($token)->getJson('/api/v1/admin/roles')->assertForbidden();
    $admin->givePermissionTo('ViewAny_Role');
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/admin/roles')->assertOk();
    $this->postJson('/api/v1/admin/roles', ['name' => 'support'])->assertForbidden();
});

test('super administrator manages roles and permissions with invokable admin endpoints', function () {
    $admin = adminSecurityUser();
    $token = adminSecurityToken($admin);
    $id = $this->withToken($token)->postJson('/api/v1/admin/roles', ['name' => 'support'])->assertCreated()->json('data.id');
    $this->getJson('/api/v1/admin/roles?page=1&per_page=2')->assertOk()->assertJsonPath('meta.perPage', 2);
    $this->getJson('/api/v1/admin/permissions')->assertOk()->assertJsonFragment(['ViewAny_Role']);
    $this->getJson("/api/v1/admin/roles/{$id}")->assertOk()->assertJsonPath('data.name', 'support');
    $this->patchJson("/api/v1/admin/roles/{$id}", ['name' => 'support_team'])->assertOk();
    $this->putJson("/api/v1/admin/roles/{$id}/permissions", ['permissions' => ['ViewAny_User', 'View_User']])->assertOk()->assertJsonPath('data.permissions', ['ViewAny_User', 'View_User']);
    $this->deleteJson("/api/v1/admin/roles/{$id}")->assertNoContent();
    $this->assertDatabaseMissing('roles', ['id' => $id]);
});

test('protects system roles and prevents granting permissions the administrator lacks', function () {
    $admin = adminSecurityUser('admin');
    $admin->givePermissionTo(['Create_Role', 'Update_Role', 'Delete_Role']);
    $token = adminSecurityToken($admin);
    $id = $this->withToken($token)->postJson('/api/v1/admin/roles', ['name' => 'support'])->assertCreated()->json('data.id');
    $this->putJson("/api/v1/admin/roles/{$id}/permissions", ['permissions' => ['Delete_User']])->assertForbidden();
    $system = Role::findByName('super_admin', 'web')->id;
    $this->patchJson("/api/v1/admin/roles/{$system}", ['name' => 'renamed'])->assertUnprocessable();
    $this->deleteJson("/api/v1/admin/roles/{$system}")->assertUnprocessable();
    $this->putJson("/api/v1/admin/roles/{$system}/permissions", ['permissions' => []])->assertUnprocessable();
    $userRole = Role::findByName('user', 'web')->id;
    $this->putJson("/api/v1/admin/roles/{$userRole}/permissions", ['permissions' => ['Create_Role']])->assertUnprocessable();
});
