<?php

declare(strict_types=1);

/*
 * WhatsApp y Telegram.
 *
 * Un webhook es la única puerta del sistema que no pide contraseña y que
 * cualquiera en internet puede empujar. Estas pruebas están escritas desde ahí:
 * que la firma se compruebe ANTES que nada, que un reintento no conteste dos
 * veces, y que un mensaje para un negocio no acabe contestado por otro.
 */

use App\Models\Catalog\CategoryModel;
use App\Models\Catalog\ProductModel;
use App\Models\Channels\ChannelAccountModel;
use App\Models\Channels\ConversationModel;
use App\Models\Channels\MessageModel;
use App\Models\Orders\OrderModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Channels\Infrastructure\Services\ChannelRouter;
use Modules\Orders\Application\UseCases\AdvanceOrder;
use Modules\Orders\Domain\ValueObjects\OrderStatus;

const SECRETO = 'un-secreto-de-webhook';

beforeEach(function (): void {
    // Los adaptadores llaman de verdad a Meta y a Telegram. Aquí no.
    Http::fake();

    $sufijo = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$sufijo}";
    $this->tenant = makeTenant($this->slug, plan: 'negocio');
    $this->numero = '5551'.random_int(100000, 999999);

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'kitchen', 'portal', 'channels'] as $modulo) {
        enableModule($this->tenant, $modulo);
    }

    $this->cuenta = ChannelAccountModel::create([
        'channel' => 'whatsapp',
        'external_id' => $this->numero,
        'webhook_secret' => SECRETO,
        'credentials' => ['access_token' => 'un-token'],
        'is_active' => true,
    ]);

    app(ChannelRouter::class)->register('whatsapp', $this->numero, $this->tenant);

    $categoria = CategoryModel::create(['name' => 'Arepas']);
    $this->arepa = ProductModel::create([
        'name' => 'Reina Pepiada', 'price_cents' => 300, 'category_id' => $categoria->id,
    ]);
});

/** El cuerpo que manda Meta cuando alguien escribe. */
function cuerpoDeWhatsApp(string $numero, string $texto = 'hola', ?string $boton = null, string $id = 'wamid.1'): array
{
    $mensaje = ['id' => $id, 'from' => '584141234567', 'timestamp' => '1', 'type' => 'text'];

    if ($boton !== null) {
        $mensaje['type'] = 'interactive';
        $mensaje['interactive'] = ['type' => 'button_reply', 'button_reply' => ['id' => $boton, 'title' => 'x']];
    } else {
        $mensaje['text'] = ['body' => $texto];
    }

    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '1',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['phone_number_id' => $numero],
                    'contacts' => [['wa_id' => '584141234567', 'profile' => ['name' => 'Ana']]],
                    'messages' => [$mensaje],
                ],
            ]],
        ]],
    ];
}

/** Llama al webhook FIRMANDO como lo haría Meta. */
/**
 * Lo último que se le contestó al cliente.
 *
 * Se desempata por `id` porque las marcas de tiempo se guardan con precisión
 * de segundos, y las dos respuestas de un mismo webhook caen en el mismo. El
 * uuid7 lleva el tiempo dentro, así que ordena bien.
 */
function ultimaSalida(): ?string
{
    return MessageModel::where('direction', 'out')
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->first()?->content;
}

function webhookDeWhatsApp(array $cuerpo, ?string $secreto = SECRETO): TestResponse
{
    $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

    if ($secreto !== null) {
        $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', (string) $json, $secreto);
    }

    return test()->call('POST', 'http://localhost/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        ...($secreto !== null
            ? ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) $json, $secreto)]
            : []),
    ], (string) $json);
}

it('un mensaje firmado entra, y el bot contesta con el menú', function (): void {
    webhookDeWhatsApp(cuerpoDeWhatsApp($this->numero))->assertOk();

    actingForTenant($this->tenant);

    $conversation = ConversationModel::first();
    expect($conversation)->not->toBeNull()
        ->and($conversation->external_chat_id)->toBe('584141234567')
        ->and($conversation->customer_name)->toBe('Ana');

    // Se guarda lo que dijo Y lo que se le contestó: media conversación no
    // sirve para nada cuando el encargado la abre.
    $salida = MessageModel::where('direction', 'out')->first();
    expect($salida?->content)->toContain('¿Qué quieres hacer?');
});

it('sin firma no entra nada', function (): void {
    // Cualquiera puede hacer un POST a esta dirección.
    webhookDeWhatsApp(cuerpoDeWhatsApp($this->numero), secreto: null)->assertForbidden();

    actingForTenant($this->tenant);
    expect(ConversationModel::count())->toBe(0);
});

it('una firma que no cuadra tampoco', function (): void {
    webhookDeWhatsApp(cuerpoDeWhatsApp($this->numero), secreto: 'otro-secreto')->assertForbidden();

    actingForTenant($this->tenant);
    expect(ConversationModel::count())->toBe(0);
});

it('un mensaje repetido NO se contesta dos veces', function (): void {
    // Meta reintenta: cuando el servidor tarda, cuando devuelve algo que no es
    // 200, y a veces sin razón aparente.
    $cuerpo = cuerpoDeWhatsApp($this->numero, id: 'wamid.repetido');

    webhookDeWhatsApp($cuerpo)->assertOk();
    webhookDeWhatsApp($cuerpo)->assertOk();

    actingForTenant($this->tenant);

    expect(MessageModel::where('direction', 'in')->count())->toBe(1);
});

it('un POST sin firma NO puede quemar el identificador de un mensaje legítimo', function (): void {
    /*
     * Es el orden lo que se prueba aquí, y es el fallo más difícil de ver de
     * todo el módulo: si se deduplicara ANTES de comprobar la firma, cualquiera
     * podría mandar un POST sin firmar con el identificador de un mensaje que
     * viene en camino, y el de verdad llegaría y se descartaría por repetido.
     *
     * El cliente escribe, no recibe nada, y en los registros no hay ningún
     * error.
     */
    $cuerpo = cuerpoDeWhatsApp($this->numero, id: 'wamid.legitimo');

    webhookDeWhatsApp($cuerpo, secreto: null)->assertForbidden();

    // El de verdad llega después y SÍ se procesa.
    webhookDeWhatsApp($cuerpo)->assertOk();

    actingForTenant($this->tenant);
    expect(MessageModel::where('direction', 'in')->count())->toBe(1);
});

it('un webhook de un número que no conocemos se cierra con 200', function (): void {
    // 200 y no 404: Meta reintenta todo lo que no sea 200, y reintentar un
    // mensaje de un negocio que ya no existe es gastar los dos lados.
    webhookDeWhatsApp(cuerpoDeWhatsApp('999999999999'))->assertOk();

    actingForTenant($this->tenant);
    expect(ConversationModel::count())->toBe(0);
});

it('los avisos de entregado y leído no se contestan', function (): void {
    // `statuses` no son mensajes de nadie. Sin descartarlos, el bot contesta un
    // menú cada vez que el cliente abre el chat.
    $cuerpo = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => $this->numero],
                    'statuses' => [['id' => 'wamid.1', 'status' => 'read']],
                ],
            ]],
        ]],
    ];

    webhookDeWhatsApp($cuerpo)->assertOk();

    actingForTenant($this->tenant);
    expect(ConversationModel::count())->toBe(0);
});

it('tocar «Ver la carta» enseña las categorías, y tocar una enseña sus productos', function (): void {
    webhookDeWhatsApp(cuerpoDeWhatsApp($this->numero, boton: 'carta', id: 'wamid.a'))->assertOk();

    actingForTenant($this->tenant);
    expect(ultimaSalida())
        ->toContain('¿Qué te provoca?');

    webhookDeWhatsApp(cuerpoDeWhatsApp($this->numero, boton: 'c:0', id: 'wamid.b'))->assertOk();

    actingForTenant($this->tenant);

    $ultimo = ultimaSalida();

    expect($ultimo)->toContain('Reina Pepiada')
        // Y el enlace al portal: el carrito se arma allá, no en el chat.
        ->and($ultimo)->toContain("http://{$this->slug}.localhost:8010/");
});

it('pedir hablar con una persona CALLA al bot', function (): void {
    webhookDeWhatsApp(cuerpoDeWhatsApp($this->numero, boton: 'persona', id: 'wamid.p'))->assertOk();

    actingForTenant($this->tenant);
    expect(ConversationModel::first()?->is_human_takeover)->toBeTrue();

    $antes = MessageModel::where('direction', 'out')->count();

    // Escribe otra vez y el bot no contesta: el cliente está hablando con una
    // persona, y un menú automático encima sería lo contrario de lo que pidió.
    webhookDeWhatsApp(cuerpoDeWhatsApp($this->numero, texto: 'gracias', id: 'wamid.q'))->assertOk();

    actingForTenant($this->tenant);
    expect(MessageModel::where('direction', 'out')->count())->toBe($antes);
});

it('la conversación tomada se suelta sola pasado el rato', function (): void {
    webhookDeWhatsApp(cuerpoDeWhatsApp($this->numero, boton: 'persona', id: 'wamid.p2'))->assertOk();

    actingForTenant($this->tenant);

    // Sin esto, el encargado atiende a alguien, se va a cerrar, y el bot queda
    // mudo para ese cliente PARA SIEMPRE.
    ConversationModel::query()->update(['takeover_at' => now()->subHours(3)]);

    webhookDeWhatsApp(cuerpoDeWhatsApp($this->numero, texto: 'hola', id: 'wamid.r'))->assertOk();

    actingForTenant($this->tenant);
    expect(ConversationModel::first()?->is_human_takeover)->toBeFalse();
});

it('las credenciales se guardan CIFRADAS', function (): void {
    // Aquí dentro está el token con el que se puede escribir a todos los
    // clientes del negocio en su nombre. Un volcado que se filtre no puede ser
    // además una lista de tokens listos para usar.
    actingForTenant($this->tenant);

    $crudo = (string) DB::table('channel_accounts')->where('id', $this->cuenta->id)->value('credentials');

    expect($crudo)->not->toContain('un-token')
        ->and(ChannelAccountModel::find($this->cuenta->id)?->credential('access_token'))->toBe('un-token');
});

it('el token NUNCA vuelve por la API', function (): void {
    $maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María');
    giveRole($this->tenant, $maria, 'owner');

    entrarComo($this->slug, 'maria@ejemplo.com');

    $respuesta = test()->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/channels'))
        ->assertOk();

    expect(json_encode($respuesta->json()))->not->toContain('un-token')
        ->and($respuesta->json('data.0.connected'))->toBeTrue();
});

it('el aviso de «listo» sale por donde el cliente escribió', function (): void {
    webhookDeWhatsApp(cuerpoDeWhatsApp($this->numero, id: 'wamid.hola'))->assertOk();

    actingForTenant($this->tenant);

    $order = OrderModel::create([
        'number' => 1,
        'public_token' => Str::random(22),
        'total_cents' => 300,
        'customer_phone' => '584141234567',
        'placed_at' => now(),
        'status' => 'confirmed',
    ]);

    $antes = MessageModel::count();

    app(AdvanceOrder::class)
        ->execute((string) $order->id, OrderStatus::Preparing);

    actingForTenant($this->tenant);

    // «En preparación» no se avisa: para quien espera es lo mismo que
    // «confirmado», y dos mensajes casi iguales se leen como spam.
    expect(MessageModel::count())->toBe($antes);

    app(AdvanceOrder::class)
        ->execute((string) $order->id, OrderStatus::Ready);

    actingForTenant($this->tenant);

    $aviso = MessageModel::where('message_type', 'notification')->latest('created_at')->first();

    expect($aviso?->content)->toContain('listo')
        ->and($aviso?->content)->toContain($order->public_token);
});

it('sin conversación no hay a quién avisarle, y no pasa nada', function (): void {
    // Pidió por el portal sin haber escrito nunca al bot.
    actingForTenant($this->tenant);

    $order = OrderModel::create([
        'number' => 2,
        'public_token' => Str::random(22),
        'total_cents' => 300,
        'customer_phone' => '584149999999',
        'placed_at' => now(),
        'status' => 'confirmed',
    ]);

    app(AdvanceOrder::class)
        ->execute((string) $order->id, OrderStatus::Preparing);

    actingForTenant($this->tenant);
    expect(MessageModel::count())->toBe(0);
});

afterEach(function (): void {
    // La deduplicación vive en la caché, que no se deshace con la transacción.
    Cache::flush();
});
