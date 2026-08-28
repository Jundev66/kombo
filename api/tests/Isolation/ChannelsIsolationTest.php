<?php

declare(strict_types=1);

/*
 * Los canales son la puerta donde el aislamiento importa más y donde es más
 * fácil de romper: el negocio no se resuelve por subdominio sino por lo que
 * trae el cuerpo de un webhook que cualquiera puede mandar.
 */

use App\Models\Channels\ChannelAccountModel;
use App\Models\Channels\ConversationModel;
use App\Models\Channels\MessageModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Channels\Infrastructure\Services\ChannelRouter;

beforeEach(function (): void {
    $sufijo = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$sufijo}");
    $this->pizzeria = makeTenant("laesquina-{$sufijo}");

    $this->numeros = [];

    foreach ([$this->arepera => 'Arepera', $this->pizzeria => 'Pizzería'] as $negocio => $nombre) {
        actingForTenant($negocio);

        $numero = '5551'.random_int(100000, 999999);
        $this->numeros[$negocio] = $numero;

        ChannelAccountModel::create([
            'channel' => 'whatsapp',
            'external_id' => $numero,
            'webhook_secret' => "secreto-de-{$nombre}",
            'credentials' => ['access_token' => "token-de-{$nombre}"],
        ]);

        $conversation = ConversationModel::create([
            'channel' => 'whatsapp',
            'external_chat_id' => '58414'.random_int(1000000, 9999999),
            'customer_name' => "Cliente de {$nombre}",
        ]);

        $conversation->messages()->create([
            'direction' => 'in',
            'content' => "Mensaje para {$nombre}",
        ]);

        app(ChannelRouter::class)->register('whatsapp', $numero, $negocio);
    }
});

it('cada negocio ve sólo sus conversaciones', function (): void {
    actingForTenant($this->arepera);
    expect(ConversationModel::pluck('customer_name')->all())->toBe(['Cliente de Arepera']);

    actingForTenant($this->pizzeria);
    expect(ConversationModel::pluck('customer_name')->all())->toBe(['Cliente de Pizzería']);
});

it('los mensajes de un chat ajeno tampoco se ven', function (): void {
    actingForTenant($this->arepera);

    expect(MessageModel::count())->toBe(1)
        ->and(MessageModel::first()?->content)->toBe('Mensaje para Arepera');
});

it('las credenciales de un negocio no existen para otro', function (): void {
    // Es lo más grave que podría filtrarse de este módulo: con ese token se le
    // escribe a todos los clientes del vecino en su nombre.
    actingForTenant($this->pizzeria);

    expect(ChannelAccountModel::count())->toBe(1)
        ->and(ChannelAccountModel::first()?->credential('access_token'))->toBe('token-de-Pizzería');
});

it('un número de WhatsApp resuelve a UN solo negocio', function (): void {
    $router = app(ChannelRouter::class);

    expect($router->tenantFor('whatsapp', $this->numeros[$this->arepera]))->toBe($this->arepera)
        ->and($router->tenantFor('whatsapp', $this->numeros[$this->pizzeria]))->toBe($this->pizzeria)
        // Y uno que no es de nadie no resuelve a ninguno, en vez de al primero
        // que aparezca.
        ->and($router->tenantFor('whatsapp', '000000000000'))->toBeNull();
});

it('el mismo número no puede darse de alta en dos negocios', function (): void {
    // Sin el único global, un negocio podría reclamar el número de otro y
    // empezar a recibir sus mensajes.
    expect(fn () => DB::table('channel_routes')->insert([
        'id' => (string) Str::uuid7(),
        'channel' => 'whatsapp',
        'external_id' => $this->numeros[$this->arepera],
        'tenant_id' => $this->pizzeria,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('sin negocio en contexto no se ve ninguna conversación', function (): void {
    withoutTenant();

    expect(DB::table('conversations')->count())->toBe(0)
        ->and(DB::table('messages')->count())->toBe(0)
        ->and(DB::table('channel_accounts')->count())->toBe(0);
});

it('no se puede colar una conversación en el chat de otro negocio', function (): void {
    actingForTenant($this->arepera);

    expect(fn () => DB::table('conversations')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->pizzeria,
        'channel' => 'whatsapp',
        'external_chat_id' => '584140000000',
        'state' => 'idle',
        'state_data' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('la guía de webhooks NO guarda credenciales', function (): void {
    /*
     * `channel_routes` es tabla de plataforma: se lee sin negocio en contexto,
     * así que no lleva RLS. Por eso sólo puede contener lo justo para saber de
     * quién es un número — si aquí hubiera un token, estaría al alcance de
     * cualquier consulta que se olvide de filtrar.
     */
    $columnas = DB::getSchemaBuilder()->getColumnListing('channel_routes');

    expect($columnas)->not->toContain('credentials')
        ->not->toContain('webhook_secret')
        ->toContain('tenant_id');
});

afterEach(function (): void {
    Cache::flush();
});
