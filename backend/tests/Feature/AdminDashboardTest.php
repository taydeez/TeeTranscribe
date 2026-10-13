<?php

use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Auth\Contracts\SocialAuthGatewayInterface;
use App\Domain\Auth\Entities\SocialIdentity;
use App\Infrastructure\Persistence\Eloquent\Models\BillingQuote;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\CreditWallet;
use App\Infrastructure\Persistence\Eloquent\Models\Payment;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Jobs\ResolveSignInLocation;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->startOfDay()->addHours(12));
    config(['billing.free_credits' => '0', 'services.signup_location.enabled' => true]);
    $this->seed(PermissionsSeeder::class);
    Queue::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->create()->assignRole('super_admin');
    $this->adminToken = app(AuthRepositoryInterface::class)->issueToken($this->admin->id, 'admin');
});

function dashboardPurchase(User $user, string $paidAt, string $currency = 'NGN', int $amount = 50000, int $credits = 100000, string $environment = 'live', string $status = 'paid'): Payment
{
    return Payment::create(['user_id' => $user->id, 'client_key' => (string) Str::uuid(), 'reference' => (string) Str::ulid(),
        'package_key' => 'test', 'package_name' => 'Test', 'customer_email' => $user->email, 'credit_units' => $credits, 'amount_minor' => $amount,
        'currency' => $currency, 'environment' => $environment, 'status' => $status, 'expires_at' => now()->addHour(), 'paid_at' => $paidAt]);
}

function dashboardUsage(User $user, string $feature, string $date, string $status = 'consumed'): void
{
    $quote = BillingQuote::create(['user_id' => $user->id, 'client_key' => (string) Str::uuid(), 'activity' => $feature,
        'provider' => 'openai', 'model' => 'test', 'rate' => [], 'source' => [], 'request_source' => [], 'quantity' => 1000,
        'credit_units' => 250, 'status' => 'submitted', 'expires_at' => now()->addHour()]);
    UsageCharge::create(['user_id' => $user->id, 'quote_id' => $quote->id, 'transcription_id' => null,
        'activity' => in_array($feature, ['summary', 'cleanup'], true) ? 'transcript_tool' : $feature,
        'provider' => 'openai', 'model' => 'test', 'quantity' => 1000, 'credit_units' => 250, 'rate' => [], 'status' => $status, 'created_at' => $date]);
}

test('dashboard analytics require an admin session and dashboard permission', function () {
    $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    $user = User::factory()->create();
    $this->withToken(app(AuthRepositoryInterface::class)->issueToken($user->id, 'web'))->getJson('/api/v1/admin/dashboard')->assertForbidden();
    app('auth')->forgetGuards();
    $admin = User::factory()->create()->assignRole('admin');
    $admin->revokePermissionTo('View_Dashboard');
    $admin->roles()->first()->revokePermissionTo('View_Dashboard');
    $this->withToken(app(AuthRepositoryInterface::class)->issueToken($admin->id, 'admin'))->getJson('/api/v1/admin/dashboard')->assertForbidden();
});

test('dashboard counts paid purchases by payment date and currency and consumed credits by ledger date', function () {
    $user = User::factory()->create(['created_at' => '2026-10-08 06:00:00']);
    dashboardPurchase($user, '2026-10-08 12:00:00');
    dashboardPurchase($user, '2026-10-10 23:59:59', 'USD', 1000, 200000);
    dashboardPurchase($user, '2026-10-08 13:00:00', environment: 'test');
    dashboardPurchase($user, '2026-10-08 14:00:00', status: 'pending');
    dashboardPurchase($user, '2026-10-11 00:00:00');
    dashboardPurchase($this->admin, '2026-10-08 15:00:00');
    $wallet = CreditWallet::create(['user_id' => $user->id, 'available_units' => 0, 'reserved_units' => 0]);
    foreach (['consume' => 250, 'reserve' => 9999, 'release' => 9999, 'grant' => 9999] as $kind => $units) {
        CreditTransaction::create(['wallet_id' => $wallet->id, 'user_id' => $user->id, 'event_key' => (string) Str::ulid(), 'kind' => $kind,
            'amount_units' => $units, 'available_after' => 0, 'reserved_after' => 0, 'created_at' => '2026-10-09 12:00:00']);
    }
    $this->withToken($this->adminToken)->getJson('/api/v1/admin/dashboard?from=2026-10-08&to=2026-10-10')->assertOk()
        ->assertJsonPath('data.summary.credits_bought_units', 300000)->assertJsonPath('data.summary.credits_used_units', 250)
        ->assertJsonPath('data.summary.new_accounts', 1)->assertJsonCount(3, 'data.daily')
        ->assertJsonPath('data.daily.0.revenue_ngn_minor', 50000)->assertJsonPath('data.daily.1.revenue_ngn_minor', 0)
        ->assertJsonPath('data.daily.1.credits_used_units', 250)->assertJsonPath('data.daily.2.revenue_usd_minor', 1000)
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/api/v1/admin/dashboard?from=2026-10-08&to=2026-10-10&environment=test')->assertOk()->assertJsonPath('data.summary.credits_bought_units', 100000);
    $this->getJson('/api/v1/admin/dashboard?from=2026-10-08&to=2026-10-10&environment=all')->assertOk()->assertJsonPath('data.summary.credits_bought_units', 400000);
});

test('dashboard groups real feature requests and sign in countries including unknowns', function () {
    $user = User::factory()->create();
    dashboardUsage($user, 'summary', '2026-10-09 10:00:00');
    dashboardUsage($user, 'summary', '2026-10-09 11:00:00', 'reserved');
    dashboardUsage($user, 'dubbing', '2026-10-09 12:00:00', 'released');
    dashboardUsage($user, 'translation', '2026-10-07 12:00:00');
    dashboardUsage($this->admin, 'transcription', '2026-10-09 12:00:00');
    foreach (['Nigeria', 'Nigeria', null] as $country) {
        DB::table('customer_sign_ins')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'method' => 'password',
            'country' => $country, 'country_code' => $country ? 'NG' : null, 'created_at' => '2026-10-09 12:00:00']);
    }
    $this->withToken($this->adminToken)->getJson('/api/v1/admin/dashboard?from=2026-10-08&to=2026-10-10')->assertOk()
        ->assertJsonPath('data.summary.feature_requests', 3)->assertJsonPath('data.features.0.feature', 'summary')->assertJsonPath('data.features.0.requests', 2)
        ->assertJsonPath('data.summary.sign_ins', 3)->assertJsonPath('data.summary.unique_signing_users', 1)
        ->assertJsonPath('data.locations.0.country', 'Nigeria')->assertJsonPath('data.locations.0.sign_ins', 2)
        ->assertJsonPath('data.locations.1.country', 'Unknown')->assertJsonPath('data.daily.1.sign_ins', 3)
        ->assertDontSee($user->email)->assertDontSee('signup_ip');
});

test('empty dashboard periods have zero filled days and no invented distributions', function () {
    $this->withToken($this->adminToken)->getJson('/api/v1/admin/dashboard?from=2026-10-08&to=2026-10-10')->assertOk()
        ->assertJsonPath('data.summary.new_accounts', 0)->assertJsonPath('data.summary.revenue', [])
        ->assertJsonPath('data.features', [])->assertJsonPath('data.locations', [])->assertJsonCount(3, 'data.daily');
});

test('only successful customer logins are tracked and signup and admin challenges are excluded', function () {
    $user = User::factory()->create(['password' => 'password1']);
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password1'])->assertOk();
    $this->assertDatabaseCount('customer_sign_ins', 1);
    $this->assertDatabaseHas('customer_sign_ins', ['user_id' => $user->id, 'method' => 'password', 'country' => null]);
    $this->postJson('/api/v1/auth/register', ['name' => 'New customer', 'email' => 'fresh@example.com', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertCreated();
    Mail::fake();
    $admin = User::factory()->create(['password' => 'password1'])->assignRole('admin');
    $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password1'])->assertOk()->assertJsonPath('requires_two_factor', true);
    $this->assertDatabaseCount('customer_sign_ins', 1);
    Queue::assertNotPushed(ResolveSignInLocation::class);
});

test('google logins are tracked and country lookups run on the queue without storing IP addresses', function () {
    $user = User::factory()->create();
    $google = Mockery::mock(SocialAuthGatewayInterface::class);
    $google->shouldReceive('identityFromCallback')->once()->andReturn(new SocialIdentity('google-one', $user->name, $user->email));
    $this->app->instance(SocialAuthGatewayInterface::class, $google);
    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/api/v1/auth/google/callback')->assertRedirect();
    $this->assertDatabaseCount('customer_sign_ins', 1);
    Queue::assertPushed(ResolveSignInLocation::class, fn ($job) => $job instanceof ShouldBeEncrypted);
    $id = DB::table('customer_sign_ins')->value('id');
    Http::fake(['ipapi.co/*' => Http::response(['country_name' => 'Nigeria', 'country_code' => 'NG'])]);
    (new ResolveSignInLocation($id, '8.8.8.8'))->handle();
    $this->assertDatabaseHas('customer_sign_ins', ['id' => $id, 'country' => 'Nigeria', 'country_code' => 'NG', 'method' => 'google']);
    expect(DB::table('customer_sign_ins')->first())->not->toHaveProperty('ip');
});
