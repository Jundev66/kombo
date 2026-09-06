<?php

declare(strict_types=1);

/*
 * WhatsApp and Telegram.
 *
 * A webhook is the only door in the system that asks for no password and that
 * anybody on the internet can push. These tests are written from there: the
 * signature is verified BEFORE anything else, a retry does not answer twice, and
 * a message for one tenant is never answered by another.
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

const SECRET = 'un-secreto-de-webhook';

beforeEach(function (): void {
    // The adapters really call Meta and Telegram. Not here.
    Http::fake();

    $suffix = Str::lower(Str::random(6));

    $this->slug = "elsazon-{$suffix}";
    $this->tenant = makeTenant($this->slug, plan: 'business');
    $this->number = '5551'.random_int(100000, 999999);

    actingForTenant($this->tenant);
    foreach (['core', 'catalog', 'orders', 'kitchen', 'portal', 'channels'] as $module) {
        enableModule($this->tenant, $module);
    }

    $this->account = ChannelAccountModel::create([
        'channel' => 'whatsapp',
        'external_id' => $this->number,
        'webhook_secret' => SECRET,
        'credentials' => ['access_token' => 'un-token'],
        'is_active' => true,
    ]);

    app(ChannelRouter::class)->register('whatsapp', $this->number, $this->tenant);

    $category = CategoryModel::create(['name' => 'Arepas']);
    $this->arepa = ProductModel::create([
        'name' => 'Reina Pepiada', 'price_cents' => 300, 'category_id' => $category->id,
    ]);
});

/** The body Meta sends when somebody writes. */
function whatsAppPayload(string $number, string $text = 'hola', ?string $button = null, string $id = 'wamid.1'): array
{
    $message = ['id' => $id, 'from' => '584141234567', 'timestamp' => '1', 'type' => 'text'];

    if ($button !== null) {
        $message['type'] = 'interactive';
        $message['interactive'] = ['type' => 'button_reply', 'button_reply' => ['id' => $button, 'title' => 'x']];
    } else {
        $message['text'] = ['body' => $text];
    }

    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '1',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['phone_number_id' => $number],
                    'contacts' => [['wa_id' => '584141234567', 'profile' => ['name' => 'Ana']]],
                    'messages' => [$message],
                ],
            ]],
        ]],
    ];
}

/** Calls the webhook SIGNING as Meta would. */
/**
 * The last thing said back to the customer.
 *
 * Tied on `id` because timestamps have second precision and both replies to one
 * webhook land in the same second. The uuid7 carries the time inside, so it
 * orders correctly.
 */
function lastExit(): ?string
{
    return MessageModel::where('direction', 'out')
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->first()?->content;
}

function webhookDeWhatsApp(array $body, ?string $secret = SECRET): TestResponse
{
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

    if ($secret !== null) {
        $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', (string) $json, $secret);
    }

    return test()->call('POST', 'http://localhost/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        ...($secret !== null
            ? ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) $json, $secret)]
            : []),
    ], (string) $json);
}

it('a signed message gets in, and the bot answers with the menu', function (): void {
    webhookDeWhatsApp(whatsAppPayload($this->number))->assertOk();

    actingForTenant($this->tenant);

    $conversation = ConversationModel::first();
    expect($conversation)->not->toBeNull()
        ->and($conversation->external_chat_id)->toBe('584141234567')
        ->and($conversation->customer_name)->toBe('Ana');

    // What they said AND what was said back: half a conversation is no use when
    // the manager opens it.
    $output = MessageModel::where('direction', 'out')->first();
    expect($output?->content)->toContain('¿Qué quieres hacer?');
});

it('without a signature nothing gets in', function (): void {
    // Anyone can POST to this address.
    webhookDeWhatsApp(whatsAppPayload($this->number), secret: null)->assertForbidden();

    actingForTenant($this->tenant);
    expect(ConversationModel::count())->toBe(0);
});

it('nor does a signature that does not match', function (): void {
    webhookDeWhatsApp(whatsAppPayload($this->number), secret: 'otro-secreto')->assertForbidden();

    actingForTenant($this->tenant);
    expect(ConversationModel::count())->toBe(0);
});

it('a repeated message is NOT answered twice', function (): void {
    // Meta retries: when the server is slow, on any non-200, and sometimes for
    // no apparent reason.
    $body = whatsAppPayload($this->number, id: 'wamid.repetido');

    webhookDeWhatsApp($body)->assertOk();
    webhookDeWhatsApp($body)->assertOk();

    actingForTenant($this->tenant);

    expect(MessageModel::where('direction', 'in')->count())->toBe(1);
});

it('an unsigned POST canNOT burn a legitimate message\'s id', function (): void {
    /*
     * The ORDER is what is tested here, and it is the hardest failure in the
     * module to see: deduplicating before verifying the signature would let
     * anyone burn an incoming message's id with an unsigned POST, and the real
     * one would arrive and be discarded as a repeat.
     *
     * The customer writes, receives nothing, and the logs show no error.
     */
    $body = whatsAppPayload($this->number, id: 'wamid.legitimo');

    webhookDeWhatsApp($body, secret: null)->assertForbidden();

    // The real one arrives afterwards and IS processed.
    webhookDeWhatsApp($body)->assertOk();

    actingForTenant($this->tenant);
    expect(MessageModel::where('direction', 'in')->count())->toBe(1);
});

it('a webhook for a number we do not know is closed with a 200', function (): void {
    // 200 and not 404: Meta retries anything that is not a 200, and retrying a
    // message for a tenant that no longer exists wastes both ends.
    webhookDeWhatsApp(whatsAppPayload('999999999999'))->assertOk();

    actingForTenant($this->tenant);
    expect(ConversationModel::count())->toBe(0);
});

it('delivered and read receipts are not answered', function (): void {
    // `statuses` are nobody's message. Without discarding them the bot answers
    // with a menu every time the customer opens the chat.
    $body = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => $this->number],
                    'statuses' => [['id' => 'wamid.1', 'status' => 'read']],
                ],
            ]],
        ]],
    ];

    webhookDeWhatsApp($body)->assertOk();

    actingForTenant($this->tenant);
    expect(ConversationModel::count())->toBe(0);
});

it('tapping "See the menu" shows the categories, and tapping one shows its products', function (): void {
    webhookDeWhatsApp(whatsAppPayload($this->number, button: 'catalog', id: 'wamid.a'))->assertOk();

    actingForTenant($this->tenant);
    expect(lastExit())
        ->toContain('¿Qué te provoca?');

    webhookDeWhatsApp(whatsAppPayload($this->number, button: 'c:0', id: 'wamid.b'))->assertOk();

    actingForTenant($this->tenant);

    $last = lastExit();

    expect($last)->toContain('Reina Pepiada')
        // And the portal link: the basket is assembled there, not in the chat.
        ->and($last)->toContain("http://{$this->slug}.localhost:8010/");
});

it('asking to speak to a person SILENCES the bot', function (): void {
    webhookDeWhatsApp(whatsAppPayload($this->number, button: 'human', id: 'wamid.p'))->assertOk();

    actingForTenant($this->tenant);
    expect(ConversationModel::first()?->is_human_takeover)->toBeTrue();

    $before = MessageModel::where('direction', 'out')->count();

    // They write again and the bot stays quiet: they are talking to a person,
    // and an automated menu on top is the opposite of what they asked for.
    webhookDeWhatsApp(whatsAppPayload($this->number, text: 'gracias', id: 'wamid.q'))->assertOk();

    actingForTenant($this->tenant);
    expect(MessageModel::where('direction', 'out')->count())->toBe($before);
});

it('a taken conversation releases itself after a while', function (): void {
    webhookDeWhatsApp(whatsAppPayload($this->number, button: 'human', id: 'wamid.p2'))->assertOk();

    actingForTenant($this->tenant);

    // Without this, the manager helps somebody, goes off to close up, and the
    // bot stays mute for that customer FOREVER.
    ConversationModel::query()->update(['takeover_at' => now()->subHours(3)]);

    webhookDeWhatsApp(whatsAppPayload($this->number, text: 'hola', id: 'wamid.r'))->assertOk();

    actingForTenant($this->tenant);
    expect(ConversationModel::first()?->is_human_takeover)->toBeFalse();
});

it('credentials are stored ENCRYPTED', function (): void {
    // In here is the token that can write to every one of the tenant's customers
    // in their name. A leaked dump must not also be a list of ready-to-use
    // tokens.
    actingForTenant($this->tenant);

    $rawBody = (string) DB::table('channel_accounts')->where('id', $this->account->id)->value('credentials');

    expect($rawBody)->not->toContain('un-token')
        ->and(ChannelAccountModel::find($this->account->id)?->credential('access_token'))->toBe('un-token');
});

it('the token NEVER comes back through the API', function (): void {
    $maria = makeUser($this->tenant, 'maria@ejemplo.com', 'María');
    giveRole($this->tenant, $maria, 'owner');

    loginAs($this->slug, 'maria@ejemplo.com');

    $response = test()->withHeaders(browsingAs($this->slug))
        ->getJson(urlFor($this->slug, '/api/v1/channels'))
        ->assertOk();

    expect(json_encode($response->json()))->not->toContain('un-token')
        ->and($response->json('data.0.connected'))->toBeTrue();
});

it('the "ready" notice goes out wherever the customer wrote from', function (): void {
    webhookDeWhatsApp(whatsAppPayload($this->number, id: 'wamid.hola'))->assertOk();

    actingForTenant($this->tenant);

    $order = OrderModel::create([
        'number' => 1,
        'public_token' => Str::random(22),
        'total_cents' => 300,
        'customer_phone' => '584141234567',
        'placed_at' => now(),
        'status' => 'confirmed',
    ]);

    $before = MessageModel::count();

    app(AdvanceOrder::class)
        ->execute((string) $order->id, OrderStatus::Preparing);

    actingForTenant($this->tenant);

    // "Preparing" is not announced: to whoever is waiting it is the same as
    // "confirmed", and two near-identical messages read as spam.
    expect(MessageModel::count())->toBe($before);

    app(AdvanceOrder::class)
        ->execute((string) $order->id, OrderStatus::Ready);

    actingForTenant($this->tenant);

    $notice = MessageModel::where('message_type', 'notification')->latest('created_at')->first();

    expect($notice?->content)->toContain('listo')
        ->and($notice?->content)->toContain($order->public_token);
});

it('with no conversation there is nobody to notify, and nothing breaks', function (): void {
    // They ordered through the portal without ever writing to the bot.
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
    // Deduplication lives in the cache, which the transaction does not roll back.
    Cache::flush();
});
