<?php

declare(strict_types=1);

/*
 * Platform administration: sign-up, billing, expiry and suspension.
 *
 * The part that decides whether this can be sold, and the one the previous
 * project left half-finished: there a `plan_expires_at` nobody read let a tenant
 * that stopped paying operate forever.
 */

use App\Models\Catalog\ProductModel;
use App\Models\Platform\PlatformUser;
use App\Models\Platform\SubscriptionModel;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Platform\Subscription\Subscriptions;
use Platform\Tenancy\TenantStatus;

beforeEach(function (): void {
    // The real plans: a helper inventing its own ceilings would test a plan that
    // does not exist in production.
    (new PlanSeeder)->run();

    $this->admin = PlatformUser::create([
        'name' => 'Administración',
        'email' => 'admin-'.Str::lower(Str::random(6)).'@kombo.test',
        'password' => 'demo1234',
        'is_active' => true,
    ]);
});

const ADMIN_HOST = 'http://admin.localhost';

/** A call to platform administration, from its own domain. */
function asPlatform(string $method, string $path, array $body = []): TestResponse
{
    return test()->withHeaders([
        'Accept' => 'application/json',
        'Origin' => ADMIN_HOST,
        'Referer' => ADMIN_HOST.'/',
    ])->json($method, ADMIN_HOST.$path, $body);
}

function loginAsPlatformAdmin(PlatformUser $admin): void
{
    test()->actingAs($admin, 'platform');
}

it('platform administration lives on its own domain, and only there', function (): void {
    loginAsPlatformAdmin($this->admin);

    asPlatform('GET', '/api/v1/platform/tenants')->assertOk();

    // The same route from a tenant's subdomain DOES NOT EXIST. Not a 403: there
    // is simply no route at that address there.
    $tenant = makeTenant('negocio-'.Str::lower(Str::random(6)));

    test()->withHeaders(['Accept' => 'application/json'])
        ->getJson('http://negocio-x.localhost/api/v1/platform/tenants')
        ->assertNotFound();

    expect($tenant)->not->toBeEmpty();
});

it('without signing in nothing is visible', function (): void {
    asPlatform('GET', '/api/v1/platform/tenants')->assertUnauthorized();
    asPlatform('GET', '/api/v1/platform/metrics')->assertUnauthorized();
});

it('a tenant session does NOT open platform administration', function (): void {
    // The failure to avoid: with the default guard, any tenant employee's
    // session would open everybody's billing.
    $tenantId = makeTenant('negocio-'.Str::lower(Str::random(6)));
    actingForTenant($tenantId);

    $userId = makeUser($tenantId, 'empleado@ejemplo.com', 'Empleado');
    giveRole($tenantId, $userId, 'owner');

    test()->actingAs(User::find($userId));

    asPlatform('GET', '/api/v1/platform/tenants')->assertUnauthorized();
});

it('signing a tenant up leaves it READY to work', function (): void {
    loginAsPlatformAdmin($this->admin);

    $slug = 'nuevo-'.Str::lower(Str::random(6));

    $response = asPlatform('POST', '/api/v1/platform/tenants', [
        'name' => 'Arepera Nueva',
        'slug' => $slug,
        'plan_code' => 'business',
        'owner_name' => 'Dueña',
        'owner_email' => 'duena@nueva.test',
        'owner_password' => 'clave-larga-123',
    ])->assertCreated();

    $tenantId = $response->json('data.tenant_id');

    // A half-created tenant is worse than none: nobody can get in to fix it,
    // because getting in needs exactly what is missing.
    actingForTenant($tenantId);

    expect(DB::table('users')->where('email', 'duena@nueva.test')->exists())->toBeTrue()
        ->and(DB::table('roles')->where('is_owner', true)->exists())->toBeTrue()
        ->and(DB::table('tenant_modules')->where('enabled', true)->count())->toBeGreaterThan(3)
        // With no hours the portal would be CLOSED and the tenant would look broken:
        // the menu shows and ordering says closed at any hour.
        ->and(DB::table('business_hours')->count())->toBe(7)
        // And with a subscription: with no expiry date the daily job cannot judge
        // this tenant.
        ->and(SubscriptionModel::where('tenant_id', $tenantId)->exists())->toBeTrue();

    // And the owner can really sign in.
    loginAs($slug, 'duena@nueva.test', 'clave-larga-123');
});

it('the tenant list paginates and shows the plan\'s name, not its code', function (): void {
    /*
     * Two fixes on a screen nobody had looked at.
     *
     * It had no cap at all: every tenant on the platform was downloaded. And it
     * showed `plan_code` raw — a lowercase identifier on a screen a person
     * reads is the same as not translating it.
     */
    loginAsPlatformAdmin($this->admin);

    makeTenant('paginado-'.Str::lower(Str::random(6)), plan: 'business');

    $list = asPlatform('GET', '/api/v1/platform/tenants')->assertOk();

    expect($list->json('meta.page'))->toBe(1)
        ->and($list->json('meta.total'))->toBeGreaterThan(0)
        ->and($list->json('meta.lastPage'))->toBeGreaterThan(0);

    $plans = array_column($list->json('data'), 'planName');

    expect($plans)->not->toContain('business')->toContain('Negocio');
});

it('sign-up is ALL or NOTHING', function (): void {
    loginAsPlatformAdmin($this->admin);

    $slug = 'repetido-'.Str::lower(Str::random(6));

    asPlatform('POST', '/api/v1/platform/tenants', [
        'name' => 'Primero', 'slug' => $slug, 'plan_code' => 'business',
        'owner_name' => 'A', 'owner_email' => 'a@x.test', 'owner_password' => 'clave-larga-123',
    ])->assertCreated();

    // The same slug again: rejected whole, leaving no half tenant.
    asPlatform('POST', '/api/v1/platform/tenants', [
        'name' => 'Segundo', 'slug' => $slug, 'plan_code' => 'business',
        'owner_name' => 'B', 'owner_email' => 'b@x.test', 'owner_password' => 'clave-larga-123',
    ])->assertStatus(422)->assertJsonValidationErrors('slug');

    expect(DB::table('tenants')->where('slug', $slug)->count())->toBe(1);
});

it('"admin" and company are reserved', function (): void {
    // A customer calling themselves `admin` would not be a pretty discovery.
    loginAsPlatformAdmin($this->admin);

    asPlatform('POST', '/api/v1/platform/tenants', [
        'name' => 'Intruso', 'slug' => 'admin', 'plan_code' => 'business',
        'owner_name' => 'A', 'owner_email' => 'a@x.test', 'owner_password' => 'clave-larga-123',
    ])->assertStatus(422)->assertJsonValidationErrors('slug');
});

it('recording a payment extends the period and REACTIVATES', function (): void {
    loginAsPlatformAdmin($this->admin);

    $tenantId = makeTenant('paga-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'business');

    // Expired and suspended.
    $subscription->update(['current_period_end' => now()->subDays(10), 'status' => 'suspended']);
    app(Subscriptions::class)->setTenantStatus($tenantId, TenantStatus::Suspended);

    asPlatform('POST', "/api/v1/platform/tenants/{$tenantId}/payments", [
        'amount_cents' => 2500,
        'method' => 'pago_movil',
        'months' => 1,
        'reference' => '998877',
    ])->assertOk();

    $subscription->refresh();

    expect($subscription->current_period_end->isFuture())->toBeTrue()
        ->and($subscription->status)->toBe('active')
        // Paying reactivates. Leaving it to a manual second step is how an
        // up-to-date customer still cannot work on Monday morning.
        ->and(DB::table('tenants')->where('id', $tenantId)->value('status'))->toBe('active');
});

it('whoever pays early does NOT lose the days they had left', function (): void {
    $tenantId = makeTenant('adelanta-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'business');

    $expiredAt = now()->addDays(10);
    $subscription->update(['current_period_end' => $expiredAt]);

    app(Subscriptions::class)->registerPayment($subscription, 2500, 'pago_movil', months: 1);

    // A month from their expiry, not from today.
    expect($subscription->refresh()->current_period_end->toDateString())
        ->toBe($expiredAt->copy()->addMonth()->toDateString());
});

it('whoever pays late does not buy days they already lived', function (): void {
    $tenantId = makeTenant('atrasa-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'business');

    $subscription->update(['current_period_end' => now()->subDays(20)]);

    app(Subscriptions::class)->registerPayment($subscription, 2500, 'pago_movil', months: 1);

    // A month from TODAY.
    expect($subscription->refresh()->current_period_end->toDateString())
        ->toBe(now()->addMonth()->toDateString());
});

it('the daily job marks overdue, and suspends once grace runs out', function (): void {
    $tenantId = makeTenant('vence-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'business');

    // Expired yesterday with five days of grace: not suspended yet.
    $subscription->update(['current_period_end' => now()->subDay(), 'grace_days' => 5]);

    app(Subscriptions::class)->sweep();

    expect($subscription->refresh()->status)->toBe('past_due')
        ->and(DB::table('tenants')->where('id', $tenantId)->value('status'))->toBe('past_due');

    // Past the grace period, yes.
    $subscription->update(['current_period_end' => now()->subDays(10)]);

    app(Subscriptions::class)->sweep();

    expect($subscription->refresh()->status)->toBe('suspended')
        ->and(DB::table('tenants')->where('id', $tenantId)->value('status'))->toBe('suspended');
});

it('sweeping twice brings no suspension forward', function (): void {
    // Idempotent: running it twice in a day has to make no difference.
    $tenantId = makeTenant('escoba-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'business');
    $subscription->update(['current_period_end' => now()->subDay(), 'grace_days' => 5]);

    $first = app(Subscriptions::class)->sweep();
    $second = app(Subscriptions::class)->sweep();

    expect($first['past_due'])->toBe(1)
        ->and($second['past_due'])->toBe(0)
        ->and($subscription->refresh()->status)->toBe('past_due');
});

it('a suspended tenant READS and EXPORTS, but does not write', function (): void {
    /*
     * Both halves matter. Cutting somebody off from their own data is not an
     * acceptable collection tactic: their orders and menu are still theirs even
     * owing us three months. What is cut off is carrying on for free.
     */
    $suffix = Str::lower(Str::random(6));
    $slug = "suspendido-{$suffix}";
    $tenantId = makeTenant($slug, plan: 'business');

    actingForTenant($tenantId);
    foreach (['core', 'catalog', 'orders'] as $module) {
        enableModule($tenantId, $module);
    }

    $maria = makeUser($tenantId, 'maria@ejemplo.com', 'María');
    giveRole($tenantId, $maria, 'owner');

    app(Subscriptions::class)->start($tenantId, 'business');
    app(Subscriptions::class)->setTenantStatus($tenantId, TenantStatus::Suspended);

    loginAs($slug, 'maria@ejemplo.com');

    // Reading, yes.
    test()->withHeaders(browsingAs($slug))
        ->getJson(urlFor($slug, '/api/v1/catalog/products'))
        ->assertOk();

    // Writing, no. And with 402, not 403: a 403 would say "you lack permission",
    // which is untrue and sends the owner to check their team's roles.
    test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, '/api/v1/catalog/products'), ['name' => 'Algo', 'price_cents' => 100])
        ->assertStatus(402)
        ->assertJsonPath('tenantStatus', 'suspended');
});

it('suspension applies at EVERY door, not the ones somebody remembered', function (): void {
    // Exactly where the previous project failed: the check was in 2 of some 20
    // controllers.
    $suffix = Str::lower(Str::random(6));
    $slug = "todas-{$suffix}";
    $tenantId = makeTenant($slug, plan: 'business');

    actingForTenant($tenantId);
    foreach (['core', 'catalog', 'orders', 'kitchen', 'portal'] as $module) {
        enableModule($tenantId, $module);
    }

    $maria = makeUser($tenantId, 'maria@ejemplo.com', 'María');
    giveRole($tenantId, $maria, 'owner');

    app(Subscriptions::class)->start($tenantId, 'business');
    app(Subscriptions::class)->setTenantStatus($tenantId, TenantStatus::Suspended);

    loginAs($slug, 'maria@ejemplo.com');

    foreach ([
        '/api/v1/orders',
        '/api/v1/catalog/categories',
        '/api/v1/exchange-rate',
    ] as $path) {
        test()->withHeaders(browsingAs($slug))
            ->postJson(urlFor($slug, $path), [])
            ->assertStatus(402, "La puerta {$path} deja escribir a un negocio suspendido");
    }

    // And the public portal takes no orders either: a suspended tenant cannot
    // carry on selling.
    test()->withHeaders(['Accept' => 'application/json'])
        ->postJson(urlFor($slug, '/api/v1/portal/orders'), [])
        ->assertStatus(402);
});

it('signing out works even while suspended', function (): void {
    // Leaving somebody locked in a session they cannot close is bad manners, and
    // it is a POST anyway.
    $suffix = Str::lower(Str::random(6));
    $slug = "salir-{$suffix}";
    $tenantId = makeTenant($slug, plan: 'business');

    actingForTenant($tenantId);
    enableModule($tenantId, 'core');

    $maria = makeUser($tenantId, 'maria@ejemplo.com', 'María');
    giveRole($tenantId, $maria, 'owner');

    app(Subscriptions::class)->start($tenantId, 'business');

    loginAs($slug, 'maria@ejemplo.com');

    app(Subscriptions::class)->setTenantStatus($tenantId, TenantStatus::Suspended);

    test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, '/api/v1/auth/logout'))
        ->assertOk();
});

it('a tenant\'s record shows its usage against the plan ceilings', function (): void {
    loginAsPlatformAdmin($this->admin);

    $tenantId = makeTenant('uso-'.Str::lower(Str::random(6)), plan: 'starter');
    app(Subscriptions::class)->start($tenantId, 'starter');

    actingForTenant($tenantId);
    enableModule($tenantId, 'catalog');
    makeUser($tenantId, 'alguien@ejemplo.com', 'Alguien');

    $response = asPlatform('GET', "/api/v1/platform/tenants/{$tenantId}")->assertOk();

    expect($response->json('data.usage.users.used'))->toBe(1)
        // The starter plan allows 2 users.
        ->and($response->json('data.usage.users.max'))->toBe(2)
        ->and($response->json('data.subscription.currentPeriodEnd'))->not->toBeNull();
});

it('support mode LOOKS and is written down', function (): void {
    loginAsPlatformAdmin($this->admin);

    $tenantId = makeTenant('soporte-'.Str::lower(Str::random(6)), plan: 'business');

    actingForTenant($tenantId);
    enableModule($tenantId, 'catalog');
    ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    asPlatform('GET', "/api/v1/platform/tenants/{$tenantId}/support")
        ->assertOk()
        ->assertJsonPath('data.products', 1);

    // Walking into a customer's house leaving no trace is the thing you do not
    // do. And this log can be shown to them.
    $registry = DB::table('platform_audit_log')
        ->where('tenant_id', $tenantId)
        ->where('action', 'support_access')
        ->first();

    expect($registry)->not->toBeNull()
        ->and($registry->platform_user_name)->toBe('Administración');
});

it('changing the plan does not switch modules off for whoever already uses them', function (): void {
    // Taking a customer's till away mid-lunch because somebody edited a plan
    // would be the worst possible side effect.
    loginAsPlatformAdmin($this->admin);

    $tenantId = makeTenant('plan-'.Str::lower(Str::random(6)), plan: 'business');
    app(Subscriptions::class)->start($tenantId, 'business');

    actingForTenant($tenantId);
    enableModule($tenantId, 'counter');

    asPlatform('POST', "/api/v1/platform/tenants/{$tenantId}/plan", ['plan_code' => 'starter'])
        ->assertOk();

    actingForTenant($tenantId);

    expect(DB::table('tenants')->where('id', $tenantId)->value('plan_code'))->toBe('starter')
        ->and(DB::table('tenant_modules')->where('module_code', 'counter')->where('enabled', true)->exists())
        ->toBeTrue();
});

it('the metrics count what is there', function (): void {
    loginAsPlatformAdmin($this->admin);

    $tenantId = makeTenant('metrica-'.Str::lower(Str::random(6)), plan: 'business');
    app(Subscriptions::class)->start($tenantId, 'business');

    $response = asPlatform('GET', '/api/v1/platform/metrics')->assertOk();

    expect($response->json('data.tenants.active'))->toBeGreaterThan(0)
        ->and($response->json('data.mrrCents'))->toBeGreaterThanOrEqual(0)
        ->and($response->json('data.ordersThisMonth'))->toBeGreaterThanOrEqual(0);
});

it('sign-up lands in the platform audit log', function (): void {
    loginAsPlatformAdmin($this->admin);

    $slug = 'bitacora-'.Str::lower(Str::random(6));

    $tenantId = asPlatform('POST', '/api/v1/platform/tenants', [
        'name' => 'Con bitácora', 'slug' => $slug, 'plan_code' => 'business',
        'owner_name' => 'A', 'owner_email' => "a-{$slug}@x.test", 'owner_password' => 'clave-larga-123',
    ])->json('data.tenant_id');

    $registry = DB::table('platform_audit_log')->where('tenant_id', $tenantId)->first();

    expect($registry?->action)->toBe('tenant.created')
        // The NAME as well as the id: the day that administrator leaves, the record
        // still has to say who it was.
        ->and($registry?->platform_user_name)->toBe('Administración');
});

it('signing up with an owner email already used in another tenant IS allowed', function (): void {
    // Half the point of being multi-tenant: the same person can run two shops,
    // entering each through its own subdomain.
    loginAsPlatformAdmin($this->admin);

    $email = 'misma-'.Str::lower(Str::random(6)).'@duena.test';
    $tenants = [];

    foreach (['uno', 'dos'] as $which) {
        $tenants[] = asPlatform('POST', '/api/v1/platform/tenants', [
            'name' => "Local {$which}",
            'slug' => "local-{$which}-".Str::lower(Str::random(6)),
            'plan_code' => 'business',
            'owner_name' => 'Dueña',
            'owner_email' => $email,
            'owner_password' => 'clave-larga-123',
        ])->assertCreated()->json('data');
    }

    /*
     * Checked by ENTERING each tenant, one at a time.
     *
     * Counting `users` from outside would give zero, and rightly: the table is
     * under RLS. That is the guarantee, not an obstacle — and a test that
     * sidestepped it with superuser SQL would stop checking what it claims to.
     */
    foreach ($tenants as $tenant) {
        actingForTenant($tenant['tenant_id']);

        expect(DB::table('users')->where('email', $email)->count())->toBe(1);

        // And each really signs into her own.
        loginAs($tenant['slug'], $email, 'clave-larga-123');
    }
});

it('a warning goes out before cutting off, and only once per expiry', function (): void {
    /*
     * Cutting somebody off without warning is the fastest way to lose a
     * customer who was going to pay. And warning every day is how warnings stop
     * being read.
     */
    $tenantId = makeTenant('avisa-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'business');

    $subscription->update(['current_period_end' => now()->addDays(7)->setTime(12, 0)]);

    $first = app(Subscriptions::class)->dueForWarning();

    expect(collect($first)->pluck('tenant_id'))->toContain($tenantId);

    // The second pass of the same day does not warn again.
    $second = app(Subscriptions::class)->dueForWarning();

    expect(collect($second)->pluck('tenant_id'))->not->toContain($tenantId);
});

it('somebody with ten days left is not warned yet', function (): void {
    $tenantId = makeTenant('lejos-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'business');

    $subscription->update(['current_period_end' => now()->addDays(10)]);

    expect(collect(app(Subscriptions::class)->dueForWarning())->pluck('tenant_id'))
        ->not->toContain($tenantId);
});

it('two customers behind the proxy do not share an attempt bucket', function (): void {
    /*
     * The failure this pins: behind Cloudflare without trusting the proxy,
     * Laravel sees the proxy's IP on every request. Limiters count per IP, so
     * the first customer to mistype a password locks everyone else out — a
     * denial of service by accident, between customers who have never met.
     */
    $attempt = fn (string $ip): TestResponse => test()->withHeaders([
        'Accept' => 'application/json',
        'X-Forwarded-For' => $ip,
    ])->postJson(ADMIN_HOST.'/api/v1/platform/auth/login', [
        'email' => $this->admin->email,
        'password' => 'la-que-no-es',
    ]);

    // Five failures from one IP lock it out.
    foreach (range(1, 5) as $attemptNo) {
        $attempt('203.0.113.10')->assertStatus(422);
    }

    expect($attempt('203.0.113.10')->json('errors.email.0'))->toContain('Demasiados intentos');

    // And another customer, from another IP, signs in fine.
    expect($attempt('198.51.100.20')->json('errors.email.0'))->toContain('no entran');
});
