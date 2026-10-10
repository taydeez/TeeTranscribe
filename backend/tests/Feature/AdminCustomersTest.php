<?php

use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

function customerListAdmin(bool $withPermission = true): string
{
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create()->assignRole('admin');
    if ($withPermission) {
        $user->givePermissionTo(Permission::findOrCreate('ViewAny_User', 'web'));
    }

    return app(AuthRepositoryInterface::class)->issueToken($user->id, 'admin');
}

test('customer listing requires authentication and an administrator role', function () {
    $this->getJson('/api/v1/admin/customers')->assertUnauthorized();
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findOrCreate('ViewAny_User', 'web'));
    $this->withToken($user->createToken('web')->plainTextToken)
        ->getJson('/api/v1/admin/customers')->assertForbidden();
});

test('customer listing requires the customer view permission even for administrators', function () {
    $this->withToken(customerListAdmin(false))->getJson('/api/v1/admin/customers')->assertForbidden();
});

test('customer listing excludes all administrator accounts and only returns public account fields', function () {
    $token = customerListAdmin();
    Role::findOrCreate('user', 'web');
    Role::findOrCreate('super_admin', 'web');
    User::factory()->create()->assignRole(['super_admin', 'user']);
    $verified = User::factory()->create(['name' => 'Ada Customer'])->assignRole('user');
    $unverified = User::factory()->unverified()->create(['name' => 'Zara Customer']);

    $response = $this->withToken($token)->getJson('/api/v1/admin/customers?sort=name_asc')
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.id', $verified->id)
        ->assertJsonPath('data.0.emailVerified', true)
        ->assertJsonPath('data.1.id', $unverified->id)
        ->assertJsonPath('data.1.emailVerified', false);

    expect(array_keys($response->json('data.0')))->toBe(['id', 'name', 'email', 'emailVerified', 'createdAt']);
    expect($response->json('data.0.createdAt'))->not->toBeNull();
});

test('administrators can search customer names and emails without case sensitivity', function () {
    $token = customerListAdmin();
    $match = User::factory()->create(['name' => 'Ada Example', 'email' => 'ada@sample.test']);
    User::factory()->create(['name' => 'Another Person', 'email' => 'other@different.test']);
    $this->withToken($token)->getJson('/api/v1/admin/customers?search=ADA')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    $this->getJson('/api/v1/admin/customers?search=SAMPLE.TEST')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
});

test('customer listing paginates and sorts registration dates with stable ordering', function () {
    $token = customerListAdmin();
    $oldest = User::factory()->create(['created_at' => now()->subDays(3)]);
    $middle = User::factory()->create(['created_at' => now()->subDays(2)]);
    $newest = User::factory()->create(['created_at' => now()->subDay()]);
    $this->withToken($token)->getJson('/api/v1/admin/customers?per_page=1&page=2&sort=oldest')
        ->assertOk()->assertJsonPath('data.0.id', $middle->id)
        ->assertJsonPath('meta', ['currentPage' => 2, 'lastPage' => 3, 'perPage' => 1, 'total' => 3]);
    $this->getJson('/api/v1/admin/customers?per_page=1&sort=newest')
        ->assertOk()->assertJsonPath('data.0.id', $newest->id);
    $this->getJson('/api/v1/admin/customers?per_page=1&sort=oldest')
        ->assertOk()->assertJsonPath('data.0.id', $oldest->id);
});

test('administrator profile returns the permissions used to show dashboard modules', function () {
    $this->withToken(customerListAdmin())->getJson('/api/v1/admin/user')
        ->assertOk()->assertJsonPath('is_admin', true)
        ->assertJsonPath('permissions', ['ViewAny_User']);
});

test('an expired administrator session cannot read customer records', function () {
    $token = customerListAdmin();
    $this->travel(6)->minutes();
    $this->withToken($token)->getJson('/api/v1/admin/customers')->assertUnauthorized();
});
