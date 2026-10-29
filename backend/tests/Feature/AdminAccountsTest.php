<?php

use App\Domain\Admin\TwoFactor\Contracts\AdminCodeMailerInterface;
use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);
beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

function accountAdmin(string $role = 'super_admin'): array
{
    $user = User::factory()->create()->assignRole($role);

    return [$user, app(AuthRepositoryInterface::class)->issueToken($user->id, 'admin')];
}
function accountInput(int $roleId): array
{
    return ['name' => 'Support Admin', 'email' => 'support@example.test', 'username' => 'support_admin',
        'password' => 'InitialPassword123', 'role_id' => $roleId];
}

test('creates administrator accounts with a hashed password and a role without leaking credentials', function () {
    [, $token] = accountAdmin();
    $role = Role::create(['name' => 'support', 'guard_name' => 'web']);
    $role->givePermissionTo('ViewAny_User');
    $response = $this->withToken($token)->postJson('/api/v1/admin/accounts', accountInput($role->id))
        ->assertCreated()->assertJsonPath('data.mustChangePassword', true)
        ->assertJsonMissingPath('data.password');
    $user = User::findOrFail($response->json('data.id'));
    expect(Hash::check('InitialPassword123', $user->password))->toBeTrue();
    expect($user->hasAllRoles(['admin', 'support']))->toBeTrue();
    $this->getJson('/api/v1/admin/accounts')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/admin/customers')->assertOk()->assertJsonPath('meta.total', 0);
});

test('new administrators must complete email two factor and change the initial password before using any workspace', function () {
    [, $rootToken] = accountAdmin();
    $role = Role::findByName('admin', 'web');
    $id = $this->withToken($rootToken)->postJson('/api/v1/admin/accounts', accountInput($role->id))->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    $code = '';
    $this->mock(AdminCodeMailerInterface::class)->shouldReceive('send')->once()->withArgs(function ($email, $value) use (&$code) {
        $code = $value;

        return $email === 'support@example.test';
    });
    $this->withHeaders(['Authorization' => ''])->postJson('/api/v1/auth/admin/login', ['email' => 'support_admin', 'password' => 'InitialPassword123'])
        ->assertOk()->assertJsonPath('email', 'support@example.test');
    $token = $this->postJson('/api/v1/auth/admin/verify', ['email' => 'support@example.test', 'code' => $code])->assertOk()->json('token');
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/admin/user')->assertOk()->assertJsonPath('must_change_password', true)->assertJsonPath('email_verified', true);
    $this->getJson('/api/v1/admin/roles')->assertForbidden()->assertJsonPath('code', 'password_change_required');
    $this->getJson('/api/v1/folders')->assertForbidden();
    $this->postJson('/api/v1/admin/password', ['current_password' => 'wrong', 'password' => 'OwnPassword12345', 'password_confirmation' => 'OwnPassword12345'])->assertUnprocessable();
    expect(User::findOrFail($id)->must_change_password)->toBeTrue();
    $this->postJson('/api/v1/admin/password', ['current_password' => 'InitialPassword123', 'password' => 'OwnPassword12345', 'password_confirmation' => 'OwnPassword12345'])->assertOk();
    $user = User::findOrFail($id);
    expect($user->must_change_password)->toBeFalse();
    expect(Hash::check('OwnPassword12345', $user->password))->toBeTrue();
    expect($user->tokens()->count())->toBe(0);
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/admin/user')->assertUnauthorized();
});

test('role updates revoke sessions and deletion removes access but preserves account history', function () {
    [, $token] = accountAdmin();
    [$target, $oldToken] = accountAdmin('admin');
    $role = Role::create(['name' => 'billing_team', 'guard_name' => 'web']);
    $role->givePermissionTo('ViewAny_PaymentTransaction');
    $this->withToken($token)->patchJson('/api/v1/admin/accounts/'.$target->id.'/role', ['role_id' => $role->id])->assertOk();
    expect($target->fresh()->hasRole('billing_team'))->toBeTrue();
    expect($target->tokens()->count())->toBe(0);
    $this->deleteJson('/api/v1/admin/accounts/'.$target->id)->assertNoContent();
    expect($target->fresh()->admin_deleted_at)->not->toBeNull();
    $this->getJson('/api/v1/admin/accounts')->assertOk()->assertJsonPath('meta.total', 1);
    app('auth')->forgetGuards();
    $this->withToken($oldToken)->getJson('/api/v1/admin/user')->assertUnauthorized();
    expect(app(AuthRepositoryInterface::class)->findByEmail($target->email))->toBeNull();
});

test('administrators cannot remove themselves or modify the last super administrator', function () {
    [$root, $token] = accountAdmin();
    $role = Role::findByName('admin', 'web');
    $this->withToken($token)->deleteJson('/api/v1/admin/accounts/'.$root->id)->assertUnprocessable();
    $this->patchJson('/api/v1/admin/accounts/'.$root->id.'/role', ['role_id' => $role->id])->assertUnprocessable();
    expect($root->fresh()->hasRole('super_admin'))->toBeTrue();
});

test('admin account operations require dedicated permissions and cannot grant higher privilege roles', function () {
    [$admin, $token] = accountAdmin('admin');
    $role = Role::findByName('super_admin', 'web');
    $this->withToken($token)->getJson('/api/v1/admin/accounts')->assertForbidden();
    $this->postJson('/api/v1/admin/accounts', accountInput($role->id))->assertForbidden();
    $admin->givePermissionTo(['Create_AdminAccount', 'Update_AdminAccount', 'Delete_AdminAccount']);
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/admin/accounts', accountInput($role->id))->assertForbidden();
    $custom = Role::create(['name' => 'powerful', 'guard_name' => 'web']);
    $custom->givePermissionTo('Delete_User');
    $this->postJson('/api/v1/admin/accounts', accountInput($custom->id))->assertForbidden();
    [$target] = accountAdmin();
    $this->deleteJson('/api/v1/admin/accounts/'.$target->id)->assertForbidden();
});

test('regular users cannot administer accounts even when granted matching permissions', function () {
    $user = User::factory()->create()->assignRole('user');
    $user->givePermissionTo(['ViewAny_AdminAccount', 'Create_AdminAccount']);
    $this->withToken($user->createToken('web')->plainTextToken)->getJson('/api/v1/admin/accounts')->assertForbidden();
});

test('username login changes preserve existing customers with mixed case email addresses', function () {
    $user = User::factory()->create(['email' => 'Existing.Customer@example.test', 'password' => 'password1'])->assignRole('user');
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password1'])->assertOk()->assertJsonPath('user.id', $user->id);
});
