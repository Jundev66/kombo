<?php

declare(strict_types=1);

/*
 * Each channel applies ITS limits, not a lowest common denominator.
 *
 * The reason there is a port and two adapters rather than one class full of
 * `if ($channel === 'whatsapp')`. Knowing the limits, the engine would write for
 * the poorer of the two and cramp Telegram for no reason.
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

function account(string $channel, array $credentials): ChannelAccountModel
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
function options(int $howMany): array
{
    return array_map(
        fn (int $i): ReplyOption => new ReplyOption("o:{$i}", "Opción número {$i}"),
        range(1, $howMany),
    );
}

it('WhatsApp splits into batches of three, because it takes no more', function (): void {
    $adapter = new WhatsAppChannel(account('whatsapp', ['access_token' => 't']));

    $adapter->send('584141234567', Reply::withOptions('Elige', options(7)));

    // Seven options are three messages: 3 + 3 + 1.
    Http::assertSentCount(3);

    $firstItem = true;
    Http::assertSent(function (Request $request) use (&$firstItem): bool {
        $buttons = $request['interactive']['action']['buttons'] ?? [];

        expect(count($buttons))->toBeLessThanOrEqual(3);

        // The text goes only in the first: repeating the whole question in each
        // batch would have the customer read it three times.
        if ($firstItem) {
            expect($request['interactive']['body']['text'])->toBe('Elige');
            $firstItem = false;
        }

        return true;
    });
});

it('WhatsApp trims long titles instead of failing', function (): void {
    $adapter = new WhatsAppChannel(account('whatsapp', ['access_token' => 't']));

    // A product being called that cannot be a system error: it is just a name
    // that does not fit on a twenty-character button.
    $adapter->send('584141234567', Reply::withOptions('Elige', [
        new ReplyOption('p:1', 'Arepa de pernil con queso amarillo y aguacate'),
    ]));

    Http::assertSent(function (Request $request): bool {
        $title = $request['interactive']['action']['buttons'][0]['reply']['title'];

        expect(mb_strlen($title))->toBeLessThanOrEqual(20)
            ->and($title)->toEndWith('…');

        return true;
    });
});

it('Telegram sends all eight options in ONE message', function (): void {
    $adapter = new TelegramChannel(account('telegram', ['bot_token' => 't']));

    $adapter->send('99', Reply::withOptions('Elige', options(8)));

    // One send: trimming to three would import a limit this channel does not
    // have.
    Http::assertSentCount(1);

    Http::assertSent(function (Request $request): bool {
        $keyboard = json_decode((string) $request['reply_markup'], true);
        $buttons = array_merge(...$keyboard['inline_keyboard']);

        expect($buttons)->toHaveCount(8)
            // Titles are NOT trimmed: they fit whole here.
            ->and($buttons[0]['text'])->toBe('Opción número 1');

        return true;
    });
});

it('Telegram discards a button whose id does not fit in 64 bytes', function (): void {
    // And it fails in the worst way if sent anyway: the API accepts it and
    // tapping does nothing, with no error anywhere.
    $adapter = new TelegramChannel(account('telegram', ['bot_token' => 't']));

    $adapter->send('99', Reply::withOptions('Elige', [
        new ReplyOption('ver_categoria_'.str_repeat('a', 80), 'Demasiado largo'),
        new ReplyOption('c:1', 'Este sí cabe'),
    ]));

    Http::assertSent(function (Request $request): bool {
        $keyboard = json_decode((string) $request['reply_markup'], true);
        $buttons = array_merge(...$keyboard['inline_keyboard']);

        expect($buttons)->toHaveCount(1)
            ->and($buttons[0]['callback_data'])->toBe('c:1');

        return true;
    });
});

it('with no credentials nobody is called, and nothing blows up', function (): void {
    // A half-configured channel cannot bring down the job that called it: the
    // notice is an extra, the food is the product.
    $adapter = new WhatsAppChannel(account('whatsapp', []));

    $adapter->send('584141234567', Reply::text('hola'));

    Http::assertNothingSent();
});

it('a channel that is down does not bring down what called it', function (): void {
    Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);

    $adapter = new TelegramChannel(account('telegram', ['bot_token' => 't']));

    // It does not throw: if it did, marking a ticket ready would fail because
    // Telegram is down.
    $adapter->send('99', Reply::text('hola'));

    expect(true)->toBeTrue();
});

it('WhatsApp\'s signature is compared in constant time against the RAW body', function (): void {
    $adapter = new WhatsAppChannel(account('whatsapp', ['access_token' => 't']));

    $body = '{"hola":"mundo"}';
    $signature = 'sha256='.hash_hmac('sha256', $body, 'secreto');

    expect($adapter->verifySignature($body, ['x-hub-signature-256' => [$signature]], 'secreto'))->toBeTrue()
        // One space of difference and the signature no longer matches, which is why
        // what arrived is signed rather than what re-serialising produces.
        ->and($adapter->verifySignature('{"hola": "mundo"}', ['x-hub-signature-256' => [$signature]], 'secreto'))->toBeFalse()
        // With no configured secret nothing is accepted. Letting it through "while
        // it is being configured" leaves the door open as long as it takes somebody
        // to find it.
        ->and($adapter->verifySignature($body, ['x-hub-signature-256' => [$signature]], null))->toBeFalse();
});

it('Telegram understands a tapped button, which does not arrive as a message', function (): void {
    $adapter = new TelegramChannel(account('telegram', ['bot_token' => 't']));

    $messages = $adapter->parse([
        'update_id' => 1,
        'callback_query' => [
            'id' => '77',
            'data' => 'c:2',
            'from' => ['first_name' => 'Ana', 'last_name' => 'Pérez'],
            'message' => ['chat' => ['id' => 12345]],
        ],
    ]);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->chatId)->toBe('12345')
        ->and($messages[0]->intent())->toBe('c:2')
        ->and($messages[0]->senderName)->toBe('Ana Pérez');
});

it('Telegram discards what is not a message', function (): void {
    $adapter = new TelegramChannel(account('telegram', ['bot_token' => 't']));

    // Edits, channel posts, people joining a group.
    expect($adapter->parse(['update_id' => 1, 'edited_message' => ['text' => 'x']]))->toBe([]);
});
