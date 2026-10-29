<?php

use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Auth\Contracts\SocialAuthGatewayInterface;
use App\Domain\Auth\Entities\SocialIdentity;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\CreditWallet;
use App\Jobs\ResolveSignupLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['billing.free_credits' => '0']);
    Role::findOrCreate('user', 'web');
    Role::findOrCreate('admin', 'web');
    foreach (['View_User', 'Update_User', 'Create_CreditLedger'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

function customerManagementAdmin(array $permissions = ['View_User', 'Update_User', 'Create_CreditLedger']): array
{
    $user = User::factory()->create()->assignRole('admin');
    $user->givePermissionTo($permissions);

    return [$user, app(AuthRepositoryInterface::class)->issueToken($user->id, 'admin')];
}

test('customer detail exposes location balance and action history only to permitted administrators', function () {
    [$admin, $token] = customerManagementAdmin();
    $customer = User::factory()->create();
    $customer->forceFill(['signup_ip' => '8.8.8.8', 'signup_location' => ['country' => 'Nigeria', 'city' => 'Lagos']])->save();
    $this->withToken($token)->getJson('/api/v1/admin/customers/'.$customer->id)
        ->assertOk()->assertJsonPath('data.signupIp', '8.8.8.8')
        ->assertJsonPath('data.signupLocation.city', 'Lagos')
        ->assertJsonPath('balance.available_units', 0)->assertJsonPath('data.actions', []);
    $this->getJson('/api/v1/admin/customers/'.$admin->id)->assertNotFound();
    app('auth')->forgetGuards();
    $this->withToken($customer->createToken('web')->plainTextToken)->getJson('/api/v1/admin/customers/'.$customer->id)->assertForbidden();
    $this->getJson('/api/v1/user')->assertOk()->assertJsonMissingPath('signup_ip')->assertJsonMissingPath('signup_location');
});

test('suspension revokes existing sessions prevents login and expires automatically', function () {
    [$admin, $token] = customerManagementAdmin();
    $customer = User::factory()->create(['password' => 'password1']);
    $oldToken = $customer->createToken('web')->plainTextToken;
    $this->withToken($token)->patchJson('/api/v1/admin/customers/'.$customer->id.'/access', [
        'action' => 'suspend', 'days' => 2, 'reason' => 'Repeated abuse',
    ])->assertOk()->assertJsonPath('data.status', 'suspended');
    expect($customer->tokens()->count())->toBe(0);
    $this->assertDatabaseHas('customer_admin_actions', ['customer_id' => $customer->id, 'admin_id' => $admin->id, 'reason' => 'Repeated abuse']);
    app('auth')->forgetGuards();
    $this->withToken($oldToken)->getJson('/api/v1/folders')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->postJson('/api/v1/auth/login', ['email' => $customer->email, 'password' => 'password1'])->assertUnauthorized();
    $this->travel(3)->days();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['email' => $customer->email, 'password' => 'password1'])->assertOk()->assertJsonStructure(['token']);
});

test('permanent blocking remains active until restored and cookies cannot bypass it', function () {
    [, $token] = customerManagementAdmin();
    $customer = User::factory()->create(['password' => 'password1']);
    $path = '/api/v1/admin/customers/'.$customer->id.'/access';
    $this->withToken($token)->patchJson($path, ['action' => 'block', 'reason' => 'Fraud investigation'])->assertOk()->assertJsonPath('data.status', 'blocked');
    expect($customer->fresh()->isRestricted())->toBeTrue();
    $this->patchJson($path, ['action' => 'restore', 'reason' => 'Investigation resolved'])->assertOk()->assertJsonPath('data.status', 'active');
    expect($customer->fresh()->isRestricted())->toBeFalse();
    $customer->forceFill(['account_status' => 'blocked'])->save();
    app('auth')->forgetGuards();
    $this->actingAs($customer, 'sanctum')->getJson('/api/v1/folders')->assertForbidden();
});

test('credit adjustments are atomic recorded with reason and safe to retry', function () {
    [$admin, $token] = customerManagementAdmin();
    $customer = User::factory()->create();
    $path = '/api/v1/admin/customers/'.$customer->id.'/credits';
    $input = ['action' => 'add', 'credit_units' => 5050, 'client_key' => (string) Str::uuid(), 'reason' => 'Service compensation'];
    $this->withToken($token)->postJson($path, $input)->assertOk()->assertJsonPath('balance.available_units', 5050);
    $this->postJson($path, $input)->assertOk()->assertJsonPath('balance.available_units', 5050);
    expect(CreditTransaction::where('kind', 'admin_add')->count())->toBe(1);
    $entry = CreditTransaction::where('kind', 'admin_add')->firstOrFail();
    expect($entry->metadata)->toBe(['admin_id' => $admin->id, 'reason' => 'Service compensation']);
    expect(DB::table('customer_admin_actions')->where('action', 'credits_add')->count())->toBe(1);
    $this->postJson($path, [...$input, 'credit_units' => 6000])->assertConflict();
    $this->postJson($path, ['action' => 'remove', 'credit_units' => 50, 'client_key' => (string) Str::uuid(), 'reason' => 'Correction'])
        ->assertOk()->assertJsonPath('balance.available_units', 5000);
    expect(CreditTransaction::where('kind', 'admin_remove')->firstOrFail()->amount_units)->toBe(50);
});

test('removal cannot use reserved credits and failure rolls back audit and ledger entries', function () {
    [, $token] = customerManagementAdmin();
    $customer = User::factory()->create();
    CreditWallet::create(['user_id' => $customer->id, 'available_units' => 100, 'reserved_units' => 900]);
    $this->withToken($token)->postJson('/api/v1/admin/customers/'.$customer->id.'/credits', [
        'action' => 'remove', 'credit_units' => 101, 'client_key' => (string) Str::uuid(), 'reason' => 'Correction',
    ])->assertUnprocessable();
    expect(CreditWallet::where('user_id', $customer->id)->firstOrFail()->available_units)->toBe(100);
    expect(CreditWallet::where('user_id', $customer->id)->firstOrFail()->reserved_units)->toBe(900);
    $this->assertDatabaseCount('customer_admin_actions', 0);
    $this->assertDatabaseCount('credit_transactions', 0);
});

test('view permission cannot be used to suspend customers or adjust credit balances', function () {
    [, $token] = customerManagementAdmin(['View_User']);
    $customer = User::factory()->create();
    $this->withToken($token)->patchJson('/api/v1/admin/customers/'.$customer->id.'/access', ['action' => 'block', 'reason' => 'Not allowed'])->assertForbidden();
    $this->postJson('/api/v1/admin/customers/'.$customer->id.'/credits', ['action' => 'add', 'credit_units' => 100, 'client_key' => (string) Str::uuid(), 'reason' => 'Not allowed'])->assertForbidden();
    $this->assertDatabaseCount('customer_admin_actions', 0);
});

test('signup captures server observed IP without trusting spoofed forwarding headers', function () {
    Bus::fake([ResolveSignupLocation::class]);
    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->withHeaders(['X-Forwarded-For' => '1.1.1.1'])
        ->postJson('/api/v1/auth/register', ['name' => 'New Customer', 'email' => 'new@example.test', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertCreated();
    $customer = User::where('email', 'new@example.test')->firstOrFail();
    expect($customer->signup_ip)->toBe('8.8.8.8');
    Bus::assertDispatched(ResolveSignupLocation::class, fn ($job) => $job->userId === $customer->id);
});

test('google signup records location only on initial account creation', function () {
    Bus::fake([ResolveSignupLocation::class]);
    $this->mock(SocialAuthGatewayInterface::class)->shouldReceive('identityFromCallback')->twice()
        ->andReturn(new SocialIdentity('google-123', 'Google Customer', 'social@example.test'));
    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/api/v1/auth/google/callback')->assertRedirect();
    expect(User::where('email', 'social@example.test')->firstOrFail()->signup_ip)->toBe('8.8.8.8');
    app('auth')->forgetGuards();
    $this->withServerVariables(['REMOTE_ADDR' => '1.1.1.1'])->get('/api/v1/auth/google/callback')->assertRedirect();
    expect(User::where('email', 'social@example.test')->firstOrFail()->signup_ip)->toBe('8.8.8.8');
});

test('signup geolocation runs asynchronously and leaves private IPs unresolved', function () {
    Http::fake(['ipapi.co/*' => Http::response(['country_name' => 'Nigeria', 'country_code' => 'NG', 'city' => 'Lagos', 'region' => 'Lagos'])]);
    $customer = User::factory()->create();
    $customer->forceFill(['signup_ip' => '8.8.8.8'])->save();
    (new ResolveSignupLocation($customer->id))->handle();
    expect($customer->fresh()->signup_location['country'])->toBe('Nigeria');
    Http::assertSentCount(1);
    (new ResolveSignupLocation($customer->id))->handle();
    Http::assertSentCount(1);
    $local = User::factory()->create();
    $local->forceFill(['signup_ip' => '127.0.0.1'])->save();
    (new ResolveSignupLocation($local->id))->handle();
    Http::assertSentCount(1);
    expect($local->fresh()->signup_location)->toBeNull();
});

test('a location service error never fabricates a customer location', function () {
    Http::fake(['ipapi.co/*' => Http::response(['error' => true, 'reason' => 'RateLimited'])]);
    $customer = User::factory()->create();
    $customer->forceFill(['signup_ip' => '8.8.8.8'])->save();
    expect(fn () => (new ResolveSignupLocation($customer->id))->handle())->toThrow(RuntimeException::class);
    expect($customer->fresh()->signup_location)->toBeNull();
});
