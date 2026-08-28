<?php

declare(strict_types=1);

/*
 * Cada canal aplica SUS límites, no un mínimo común.
 *
 * Es la razón de que haya un puerto y dos adaptadores en vez de una clase con
 * `if ($canal === 'whatsapp')`. Si el motor conociera los límites, escribiría
 * para el más pobre de los dos y Telegram quedaría igual de estrecho que
 * WhatsApp sin ninguna razón.
 */

use App\Models\Channels\ChannelAccountModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Channels\Domain\ValueObjects\Reply;
use Modules\Channels\Domain\ValueObjects\ReplyOption;
use Modules\Channels\Infrastructure\Adapters\TelegramChannel;
use Modules\Channels\Infrastructure\Adapters\WhatsAppChannel;

beforeEach(function (): void {
    Http::fake();

    $this->tenant = makeTenant('adaptadores-'.Str::lower(Str::random(6)));
    actingForTenant($this->tenant);
});

function cuenta(string $channel, array $credentials): ChannelAccountModel
{
    return ChannelAccountModel::create([
        'channel' => $channel,
        'external_id' => '123456',
        'webhook_secret' => 'secreto',
        'credentials' => $credentials,
        'is_active' => true,
    ]);
}

/** @return list<ReplyOption> */
function opciones(int $cuantas): array
{
    return array_map(
        fn (int $i): ReplyOption => new ReplyOption("o:{$i}", "Opción número {$i}"),
        range(1, $cuantas),
    );
}

it('WhatsApp parte en tandas de tres, porque no admite más', function (): void {
    $adapter = new WhatsAppChannel(cuenta('whatsapp', ['access_token' => 't']));

    $adapter->send('584141234567', Reply::withOptions('Elige', opciones(7)));

    // Siete opciones son tres mensajes: 3 + 3 + 1.
    Http::assertSentCount(3);

    $primero = true;
    Http::assertSent(function (Request $request) use (&$primero): bool {
        $botones = $request['interactive']['action']['buttons'] ?? [];

        expect(count($botones))->toBeLessThanOrEqual(3);

        // El texto va sólo en el primero: repetir la pregunta entera en cada
        // tanda haría que el cliente la lea tres veces sin saber cuál contestar.
        if ($primero) {
            expect($request['interactive']['body']['text'])->toBe('Elige');
            $primero = false;
        }

        return true;
    });
});

it('WhatsApp recorta los títulos largos en vez de fallar', function (): void {
    $adapter = new WhatsAppChannel(cuenta('whatsapp', ['access_token' => 't']));

    // Que un producto se llame así no puede ser un error del sistema: es sólo
    // un nombre que no cabe en un botón de veinte caracteres.
    $adapter->send('584141234567', Reply::withOptions('Elige', [
        new ReplyOption('p:1', 'Arepa de pernil con queso amarillo y aguacate'),
    ]));

    Http::assertSent(function (Request $request): bool {
        $titulo = $request['interactive']['action']['buttons'][0]['reply']['title'];

        expect(mb_strlen($titulo))->toBeLessThanOrEqual(20)
            ->and($titulo)->toEndWith('…');

        return true;
    });
});

it('Telegram manda las ocho opciones en UN mensaje', function (): void {
    $adapter = new TelegramChannel(cuenta('telegram', ['bot_token' => 't']));

    $adapter->send('99', Reply::withOptions('Elige', opciones(8)));

    // Un solo envío: recortarlo a tres sería traer aquí un límite que este
    // canal no tiene.
    Http::assertSentCount(1);

    Http::assertSent(function (Request $request): bool {
        $teclado = json_decode((string) $request['reply_markup'], true);
        $botones = array_merge(...$teclado['inline_keyboard']);

        expect($botones)->toHaveCount(8)
            // Los títulos NO se recortan: aquí caben enteros.
            ->and($botones[0]['text'])->toBe('Opción número 1');

        return true;
    });
});

it('Telegram descarta un botón cuyo identificador no cabe en 64 bytes', function (): void {
    // Y falla de la peor manera si se manda igual: la API lo acepta y al
    // tocarlo no pasa nada, sin error en ningún sitio.
    $adapter = new TelegramChannel(cuenta('telegram', ['bot_token' => 't']));

    $adapter->send('99', Reply::withOptions('Elige', [
        new ReplyOption('ver_categoria_'.str_repeat('a', 80), 'Demasiado largo'),
        new ReplyOption('c:1', 'Este sí cabe'),
    ]));

    Http::assertSent(function (Request $request): bool {
        $teclado = json_decode((string) $request['reply_markup'], true);
        $botones = array_merge(...$teclado['inline_keyboard']);

        expect($botones)->toHaveCount(1)
            ->and($botones[0]['callback_data'])->toBe('c:1');

        return true;
    });
});

it('sin credenciales no se llama a nadie, y no revienta', function (): void {
    // Un canal a medio configurar no puede tumbar el trabajo que lo llamó: el
    // aviso es un extra, la comida es el producto.
    $adapter = new WhatsAppChannel(cuenta('whatsapp', []));

    $adapter->send('584141234567', Reply::text('hola'));

    Http::assertNothingSent();
});

it('un canal caído no tumba lo que lo llamó', function (): void {
    Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);

    $adapter = new TelegramChannel(cuenta('telegram', ['bot_token' => 't']));

    // No lanza: si lanzara, marcar una comanda como lista fallaría porque
    // Telegram está caído.
    $adapter->send('99', Reply::text('hola'));

    expect(true)->toBeTrue();
});

it('la firma de WhatsApp se compara en tiempo constante y contra el cuerpo CRUDO', function (): void {
    $adapter = new WhatsAppChannel(cuenta('whatsapp', ['access_token' => 't']));

    $cuerpo = '{"hola":"mundo"}';
    $firma = 'sha256='.hash_hmac('sha256', $cuerpo, 'secreto');

    expect($adapter->verifySignature($cuerpo, ['x-hub-signature-256' => [$firma]], 'secreto'))->toBeTrue()
        // Un espacio de diferencia en el cuerpo y la firma ya no cuadra: por eso
        // se firma lo que llegó y no lo que se obtiene al volver a serializar.
        ->and($adapter->verifySignature('{"hola": "mundo"}', ['x-hub-signature-256' => [$firma]], 'secreto'))->toBeFalse()
        // Sin secreto configurado no se acepta nada. Dejar pasar «mientras se
        // configura» es dejar la puerta abierta el tiempo que tarde alguien en
        // encontrarla.
        ->and($adapter->verifySignature($cuerpo, ['x-hub-signature-256' => [$firma]], null))->toBeFalse();
});

it('Telegram entiende un botón tocado, que no llega como mensaje', function (): void {
    $adapter = new TelegramChannel(cuenta('telegram', ['bot_token' => 't']));

    $mensajes = $adapter->parse([
        'update_id' => 1,
        'callback_query' => [
            'id' => '77',
            'data' => 'c:2',
            'from' => ['first_name' => 'Ana', 'last_name' => 'Pérez'],
            'message' => ['chat' => ['id' => 12345]],
        ],
    ]);

    expect($mensajes)->toHaveCount(1)
        ->and($mensajes[0]->chatId)->toBe('12345')
        ->and($mensajes[0]->intent())->toBe('c:2')
        ->and($mensajes[0]->senderName)->toBe('Ana Pérez');
});

it('Telegram descarta lo que no es un mensaje', function (): void {
    $adapter = new TelegramChannel(cuenta('telegram', ['bot_token' => 't']));

    // Ediciones, mensajes de canal, gente que entra a un grupo.
    expect($adapter->parse(['update_id' => 1, 'edited_message' => ['text' => 'x']]))->toBe([]);
});
