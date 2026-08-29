<?php

declare(strict_types=1);

/*
 * La super administración: dar de alta, cobrar, vencer y suspender.
 *
 * Es la parte que decide si esto se puede vender, y la que en el proyecto
 * anterior quedó a medias: allí existía un `plan_expires_at` que no leía nadie,
 * así que un negocio que dejaba de pagar seguía operando para siempre.
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
    // Los planes de verdad: si el helper inventara sus propios techos, las
    // pruebas comprobarían un plan que no existe en producción.
    (new PlanSeeder)->run();

    $this->admin = PlatformUser::create([
        'name' => 'Administración',
        'email' => 'admin-'.Str::lower(Str::random(6)).'@kombo.test',
        'password' => 'demo1234',
        'is_active' => true,
    ]);
});

const ADMIN_HOST = 'http://admin.localhost';

/** Una llamada a la super administración, desde su propio dominio. */
function comoPlataforma(string $method, string $path, array $body = []): TestResponse
{
    return test()->withHeaders([
        'Accept' => 'application/json',
        'Origin' => ADMIN_HOST,
        'Referer' => ADMIN_HOST.'/',
    ])->json($method, ADMIN_HOST.$path, $body);
}

function entrarComoAdmin(PlatformUser $admin): void
{
    test()->actingAs($admin, 'platform');
}

it('la super administración vive en su propio dominio, y sólo ahí', function (): void {
    entrarComoAdmin($this->admin);

    comoPlataforma('GET', '/api/v1/platform/tenants')->assertOk();

    // La misma ruta desde el subdominio de un negocio NO EXISTE. No es que
    // responda 403: es que ahí no hay ninguna ruta con esa dirección.
    $tenant = makeTenant('negocio-'.Str::lower(Str::random(6)));

    test()->withHeaders(['Accept' => 'application/json'])
        ->getJson('http://negocio-x.localhost/api/v1/platform/tenants')
        ->assertNotFound();

    expect($tenant)->not->toBeEmpty();
});

it('sin entrar no se ve nada', function (): void {
    comoPlataforma('GET', '/api/v1/platform/tenants')->assertUnauthorized();
    comoPlataforma('GET', '/api/v1/platform/metrics')->assertUnauthorized();
});

it('la sesión de un negocio NO abre la super administración', function (): void {
    // Es el fallo que hay que evitar: con el guard por defecto, la sesión del
    // empleado de un negocio cualquiera abriría la facturación de todos.
    $tenantId = makeTenant('negocio-'.Str::lower(Str::random(6)));
    actingForTenant($tenantId);

    $userId = makeUser($tenantId, 'empleado@ejemplo.com', 'Empleado');
    giveRole($tenantId, $userId, 'owner');

    test()->actingAs(User::find($userId));

    comoPlataforma('GET', '/api/v1/platform/tenants')->assertUnauthorized();
});

it('dar de alta un negocio lo deja LISTO para trabajar', function (): void {
    entrarComoAdmin($this->admin);

    $slug = 'nuevo-'.Str::lower(Str::random(6));

    $respuesta = comoPlataforma('POST', '/api/v1/platform/tenants', [
        'name' => 'Arepera Nueva',
        'slug' => $slug,
        'plan_code' => 'negocio',
        'owner_name' => 'Dueña',
        'owner_email' => 'duena@nueva.test',
        'owner_password' => 'clave-larga-123',
    ])->assertCreated();

    $tenantId = $respuesta->json('data.tenant_id');

    // Un negocio a medio crear es peor que ninguno: nadie puede entrar a
    // arreglarlo desde dentro, porque para entrar hace falta justo lo que faltó.
    actingForTenant($tenantId);

    expect(DB::table('users')->where('email', 'duena@nueva.test')->exists())->toBeTrue()
        ->and(DB::table('roles')->where('is_owner', true)->exists())->toBeTrue()
        ->and(DB::table('tenant_modules')->where('enabled', true)->count())->toBeGreaterThan(3)
        // Sin horario, el portal estaría CERRADO y el negocio parecería roto:
        // la carta se ve y pedir contesta que está cerrado a cualquier hora.
        ->and(DB::table('business_hours')->count())->toBe(7)
        // Y con suscripción: sin fecha de vencimiento, el trabajo diario no
        // sabe qué hacer con este negocio.
        ->and(SubscriptionModel::where('tenant_id', $tenantId)->exists())->toBeTrue();

    // Y la dueña puede entrar de verdad.
    entrarComo($slug, 'duena@nueva.test', 'clave-larga-123');
});

it('el alta es TODO o NADA', function (): void {
    entrarComoAdmin($this->admin);

    $slug = 'repetido-'.Str::lower(Str::random(6));

    comoPlataforma('POST', '/api/v1/platform/tenants', [
        'name' => 'Primero', 'slug' => $slug, 'plan_code' => 'negocio',
        'owner_name' => 'A', 'owner_email' => 'a@x.test', 'owner_password' => 'clave-larga-123',
    ])->assertCreated();

    // El mismo slug otra vez: se rechaza entero, sin dejar medio negocio.
    comoPlataforma('POST', '/api/v1/platform/tenants', [
        'name' => 'Segundo', 'slug' => $slug, 'plan_code' => 'negocio',
        'owner_name' => 'B', 'owner_email' => 'b@x.test', 'owner_password' => 'clave-larga-123',
    ])->assertStatus(422)->assertJsonValidationErrors('slug');

    expect(DB::table('tenants')->where('slug', $slug)->count())->toBe(1);
});

it('«admin» y compañía están reservados', function (): void {
    // Que un cliente se llame `admin` no sería un error bonito de descubrir.
    entrarComoAdmin($this->admin);

    comoPlataforma('POST', '/api/v1/platform/tenants', [
        'name' => 'Intruso', 'slug' => 'admin', 'plan_code' => 'negocio',
        'owner_name' => 'A', 'owner_email' => 'a@x.test', 'owner_password' => 'clave-larga-123',
    ])->assertStatus(422)->assertJsonValidationErrors('slug');
});

it('registrar un pago extiende el período y REACTIVA', function (): void {
    entrarComoAdmin($this->admin);

    $tenantId = makeTenant('paga-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'negocio');

    // Se venció y lo suspendieron.
    $subscription->update(['current_period_end' => now()->subDays(10), 'status' => 'suspended']);
    app(Subscriptions::class)->setTenantStatus($tenantId, TenantStatus::Suspended);

    comoPlataforma('POST', "/api/v1/platform/tenants/{$tenantId}/payments", [
        'amount_cents' => 2500,
        'method' => 'pago_movil',
        'months' => 1,
        'reference' => '998877',
    ])->assertOk();

    $subscription->refresh();

    expect($subscription->current_period_end->isFuture())->toBeTrue()
        ->and($subscription->status)->toBe('active')
        // Pagar reactiva. Dejarlo para un segundo paso manual es cómo un
        // cliente al día sigue sin poder trabajar el lunes por la mañana.
        ->and(DB::table('tenants')->where('id', $tenantId)->value('status'))->toBe('active');
});

it('quien paga adelantado NO pierde los días que le quedaban', function (): void {
    $tenantId = makeTenant('adelanta-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'negocio');

    $vencia = now()->addDays(10);
    $subscription->update(['current_period_end' => $vencia]);

    app(Subscriptions::class)->registerPayment($subscription, 2500, 'pago_movil', months: 1);

    // Un mes desde su vencimiento, no desde hoy.
    expect($subscription->refresh()->current_period_end->toDateString())
        ->toBe($vencia->copy()->addMonth()->toDateString());
});

it('quien paga con retraso no compra días que ya vivió', function (): void {
    $tenantId = makeTenant('atrasa-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'negocio');

    $subscription->update(['current_period_end' => now()->subDays(20)]);

    app(Subscriptions::class)->registerPayment($subscription, 2500, 'pago_movil', months: 1);

    // Un mes desde HOY.
    expect($subscription->refresh()->current_period_end->toDateString())
        ->toBe(now()->addMonth()->toDateString());
});

it('el trabajo diario marca vencido, y suspende al agotarse la gracia', function (): void {
    $tenantId = makeTenant('vence-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'negocio');

    // Vencido ayer, con cinco días de gracia: todavía no se suspende.
    $subscription->update(['current_period_end' => now()->subDay(), 'grace_days' => 5]);

    app(Subscriptions::class)->sweep();

    expect($subscription->refresh()->status)->toBe('past_due')
        ->and(DB::table('tenants')->where('id', $tenantId)->value('status'))->toBe('past_due');

    // Pasada la gracia, sí.
    $subscription->update(['current_period_end' => now()->subDays(10)]);

    app(Subscriptions::class)->sweep();

    expect($subscription->refresh()->status)->toBe('suspended')
        ->and(DB::table('tenants')->where('id', $tenantId)->value('status'))->toBe('suspended');
});

it('pasar la escoba dos veces no adelanta ninguna suspensión', function (): void {
    // Idempotente: correrlo dos veces el mismo día tiene que dar igual.
    $tenantId = makeTenant('escoba-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'negocio');
    $subscription->update(['current_period_end' => now()->subDay(), 'grace_days' => 5]);

    $primera = app(Subscriptions::class)->sweep();
    $segunda = app(Subscriptions::class)->sweep();

    expect($primera['past_due'])->toBe(1)
        ->and($segunda['past_due'])->toBe(0)
        ->and($subscription->refresh()->status)->toBe('past_due');
});

it('un negocio suspendido LEE y EXPORTA, pero no escribe', function (): void {
    /*
     * Las dos mitades importan. Borrarle el acceso a sus propios datos a quien
     * confió en el sistema no es una palanca de cobro aceptable: sus pedidos y
     * su carta siguen siendo suyos aunque nos deba tres meses. Lo que se corta
     * es seguir operando gratis.
     */
    $sufijo = Str::lower(Str::random(6));
    $slug = "suspendido-{$sufijo}";
    $tenantId = makeTenant($slug, plan: 'negocio');

    actingForTenant($tenantId);
    foreach (['core', 'catalog', 'orders'] as $modulo) {
        enableModule($tenantId, $modulo);
    }

    $maria = makeUser($tenantId, 'maria@ejemplo.com', 'María');
    giveRole($tenantId, $maria, 'owner');

    app(Subscriptions::class)->start($tenantId, 'negocio');
    app(Subscriptions::class)->setTenantStatus($tenantId, TenantStatus::Suspended);

    entrarComo($slug, 'maria@ejemplo.com');

    // Leer, sí.
    test()->withHeaders(browsingAs($slug))
        ->getJson(urlFor($slug, '/api/v1/catalog/products'))
        ->assertOk();

    // Escribir, no. Y con 402, no 403: 403 diría «no tienes permiso», que es
    // mentira y manda al dueño a revisar los roles de su equipo.
    test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, '/api/v1/catalog/products'), ['name' => 'Algo', 'price_cents' => 100])
        ->assertStatus(402)
        ->assertJsonPath('tenantStatus', 'suspended');
});

it('la suspensión se aplica en TODAS las puertas, no en las que alguien recordó', function (): void {
    // Es exactamente donde falló el proyecto anterior: la comprobación estaba
    // en 2 de unos 20 controladores.
    $sufijo = Str::lower(Str::random(6));
    $slug = "todas-{$sufijo}";
    $tenantId = makeTenant($slug, plan: 'negocio');

    actingForTenant($tenantId);
    foreach (['core', 'catalog', 'orders', 'kitchen', 'portal'] as $modulo) {
        enableModule($tenantId, $modulo);
    }

    $maria = makeUser($tenantId, 'maria@ejemplo.com', 'María');
    giveRole($tenantId, $maria, 'owner');

    app(Subscriptions::class)->start($tenantId, 'negocio');
    app(Subscriptions::class)->setTenantStatus($tenantId, TenantStatus::Suspended);

    entrarComo($slug, 'maria@ejemplo.com');

    foreach ([
        '/api/v1/orders',
        '/api/v1/catalog/categories',
        '/api/v1/exchange-rate',
    ] as $ruta) {
        test()->withHeaders(browsingAs($slug))
            ->postJson(urlFor($slug, $ruta), [])
            ->assertStatus(402, "La puerta {$ruta} deja escribir a un negocio suspendido");
    }

    // Y el portal público tampoco toma pedidos: un negocio suspendido no puede
    // seguir vendiendo.
    test()->withHeaders(['Accept' => 'application/json'])
        ->postJson(urlFor($slug, '/api/v1/portal/orders'), [])
        ->assertStatus(402);
});

it('salir funciona aunque esté suspendido', function (): void {
    // Dejar a alguien encerrado en una sesión que no puede cerrar es de mal
    // gusto, y además es una petición POST.
    $sufijo = Str::lower(Str::random(6));
    $slug = "salir-{$sufijo}";
    $tenantId = makeTenant($slug, plan: 'negocio');

    actingForTenant($tenantId);
    enableModule($tenantId, 'core');

    $maria = makeUser($tenantId, 'maria@ejemplo.com', 'María');
    giveRole($tenantId, $maria, 'owner');

    app(Subscriptions::class)->start($tenantId, 'negocio');

    entrarComo($slug, 'maria@ejemplo.com');

    app(Subscriptions::class)->setTenantStatus($tenantId, TenantStatus::Suspended);

    test()->withHeaders(browsingAs($slug))
        ->postJson(urlFor($slug, '/api/v1/auth/logout'))
        ->assertOk();
});

it('la ficha de un negocio dice su uso contra los techos del plan', function (): void {
    entrarComoAdmin($this->admin);

    $tenantId = makeTenant('uso-'.Str::lower(Str::random(6)), plan: 'inicial');
    app(Subscriptions::class)->start($tenantId, 'inicial');

    actingForTenant($tenantId);
    enableModule($tenantId, 'catalog');
    makeUser($tenantId, 'alguien@ejemplo.com', 'Alguien');

    $respuesta = comoPlataforma('GET', "/api/v1/platform/tenants/{$tenantId}")->assertOk();

    expect($respuesta->json('data.usage.users.used'))->toBe(1)
        // El plan inicial admite 2 usuarios.
        ->and($respuesta->json('data.usage.users.max'))->toBe(2)
        ->and($respuesta->json('data.subscription.currentPeriodEnd'))->not->toBeNull();
});

it('el modo soporte MIRA y queda escrito', function (): void {
    entrarComoAdmin($this->admin);

    $tenantId = makeTenant('soporte-'.Str::lower(Str::random(6)), plan: 'negocio');

    actingForTenant($tenantId);
    enableModule($tenantId, 'catalog');
    ProductModel::create(['name' => 'Reina Pepiada', 'price_cents' => 300]);

    comoPlataforma('GET', "/api/v1/platform/tenants/{$tenantId}/support")
        ->assertOk()
        ->assertJsonPath('data.products', 1);

    // Entrar en casa de un cliente sin que quede rastro es lo que no se hace.
    // Y esta bitácora se le puede enseñar a él.
    $registro = DB::table('platform_audit_log')
        ->where('tenant_id', $tenantId)
        ->where('action', 'support_access')
        ->first();

    expect($registro)->not->toBeNull()
        ->and($registro->platform_user_name)->toBe('Administración');
});

it('cambiar el plan no le apaga módulos a quien ya los usa', function (): void {
    // Apagarle la caja a un cliente en mitad del almuerzo porque alguien editó
    // un plan sería el peor efecto secundario posible.
    entrarComoAdmin($this->admin);

    $tenantId = makeTenant('plan-'.Str::lower(Str::random(6)), plan: 'negocio');
    app(Subscriptions::class)->start($tenantId, 'negocio');

    actingForTenant($tenantId);
    enableModule($tenantId, 'counter');

    comoPlataforma('POST', "/api/v1/platform/tenants/{$tenantId}/plan", ['plan_code' => 'inicial'])
        ->assertOk();

    actingForTenant($tenantId);

    expect(DB::table('tenants')->where('id', $tenantId)->value('plan_code'))->toBe('inicial')
        ->and(DB::table('tenant_modules')->where('module_code', 'counter')->where('enabled', true)->exists())
        ->toBeTrue();
});

it('las métricas cuentan lo que hay', function (): void {
    entrarComoAdmin($this->admin);

    $tenantId = makeTenant('metrica-'.Str::lower(Str::random(6)), plan: 'negocio');
    app(Subscriptions::class)->start($tenantId, 'negocio');

    $respuesta = comoPlataforma('GET', '/api/v1/platform/metrics')->assertOk();

    expect($respuesta->json('data.tenants.active'))->toBeGreaterThan(0)
        ->and($respuesta->json('data.mrrCents'))->toBeGreaterThanOrEqual(0)
        ->and($respuesta->json('data.ordersThisMonth'))->toBeGreaterThanOrEqual(0);
});

it('el alta queda en la bitácora de la plataforma', function (): void {
    entrarComoAdmin($this->admin);

    $slug = 'bitacora-'.Str::lower(Str::random(6));

    $tenantId = comoPlataforma('POST', '/api/v1/platform/tenants', [
        'name' => 'Con bitácora', 'slug' => $slug, 'plan_code' => 'negocio',
        'owner_name' => 'A', 'owner_email' => "a-{$slug}@x.test", 'owner_password' => 'clave-larga-123',
    ])->json('data.tenant_id');

    $registro = DB::table('platform_audit_log')->where('tenant_id', $tenantId)->first();

    expect($registro?->action)->toBe('tenant.created')
        // El NOMBRE además del identificador: el día que ese administrador deje
        // la empresa, el registro tiene que seguir diciendo quién fue.
        ->and($registro?->platform_user_name)->toBe('Administración');
});

it('el alta con el mismo correo del dueño en otro negocio SÍ vale', function (): void {
    // Es la mitad del sentido de ser multi-negocio: la misma persona puede
    // tener dos locales, y entra a cada uno por su subdominio.
    entrarComoAdmin($this->admin);

    $correo = 'misma-'.Str::lower(Str::random(6)).'@duena.test';
    $negocios = [];

    foreach (['uno', 'dos'] as $cual) {
        $negocios[] = comoPlataforma('POST', '/api/v1/platform/tenants', [
            'name' => "Local {$cual}",
            'slug' => "local-{$cual}-".Str::lower(Str::random(6)),
            'plan_code' => 'negocio',
            'owner_name' => 'Dueña',
            'owner_email' => $correo,
            'owner_password' => 'clave-larga-123',
        ])->assertCreated()->json('data');
    }

    /*
     * Se comprueba ENTRANDO en cada negocio, uno por uno.
     *
     * Contar `users` desde fuera daría cero, y estaría bien: la tabla lleva
     * RLS, así que sin negocio en contexto no hay ninguna fila. Esa es la
     * garantía, no un estorbo — y una prueba que la esquivara con SQL de
     * superusuario dejaría de comprobar lo que dice comprobar.
     */
    foreach ($negocios as $negocio) {
        actingForTenant($negocio['tenant_id']);

        expect(DB::table('users')->where('email', $correo)->count())->toBe(1);

        // Y entra de verdad, cada una a la suya.
        entrarComo($negocio['slug'], $correo, 'clave-larga-123');
    }
});

it('se avisa antes de cortar, y sólo una vez por vencimiento', function (): void {
    /*
     * Cortarle a alguien sin haberle avisado es la forma más rápida de perder
     * un cliente que sí iba a pagar. Y avisar todos los días es cómo se
     * consigue que dejen de leerse los avisos.
     */
    $tenantId = makeTenant('avisa-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'negocio');

    $subscription->update(['current_period_end' => now()->addDays(7)->setTime(12, 0)]);

    $primera = app(Subscriptions::class)->dueForWarning();

    expect(collect($primera)->pluck('tenant_id'))->toContain($tenantId);

    // La segunda pasada del mismo día no vuelve a avisar.
    $segunda = app(Subscriptions::class)->dueForWarning();

    expect(collect($segunda)->pluck('tenant_id'))->not->toContain($tenantId);
});

it('a quien le quedan diez días todavía no se le avisa', function (): void {
    $tenantId = makeTenant('lejos-'.Str::lower(Str::random(6)));
    $subscription = app(Subscriptions::class)->start($tenantId, 'negocio');

    $subscription->update(['current_period_end' => now()->addDays(10)]);

    expect(collect(app(Subscriptions::class)->dueForWarning())->pluck('tenant_id'))
        ->not->toContain($tenantId);
});

it('dos clientes detrás del proxy no comparten el cubo de intentos', function (): void {
    /*
     * El fallo que esto fija: detrás de Cloudflare, sin confiar en el proxy,
     * Laravel ve la IP del proxy en TODAS las peticiones. Los limitadores
     * cuentan por IP, así que el primer cliente que se equivoque de contraseña
     * deja fuera a los demás — una denegación de servicio hecha por accidente,
     * entre clientes que no se conocen.
     */
    $intentar = fn (string $ip): TestResponse => test()->withHeaders([
        'Accept' => 'application/json',
        'X-Forwarded-For' => $ip,
    ])->postJson(ADMIN_HOST.'/api/v1/platform/auth/login', [
        'email' => $this->admin->email,
        'password' => 'la-que-no-es',
    ]);

    // Cinco fallos desde una IP la dejan fuera.
    foreach (range(1, 5) as $intento) {
        $intentar('203.0.113.10')->assertStatus(422);
    }

    expect($intentar('203.0.113.10')->json('errors.email.0'))->toContain('Demasiados intentos');

    // Y otro cliente, desde otra IP, entra sin problema.
    expect($intentar('198.51.100.20')->json('errors.email.0'))->toContain('no entran');
});
