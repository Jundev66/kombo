<?php

declare(strict_types=1);

/*
 * Channels are where isolation matters most and is easiest to break: the tenant
 * is resolved not from a subdomain but from the body of a webhook anyone can
 * send.
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
    $suffix = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$suffix}");
    $this->pizzeria = makeTenant("laesquina-{$suffix}");

    $this->numbers = [];

    foreach ([$this->arepera => 'Arepera', $this->pizzeria => 'Pizzería'] as $tenant => $name) {
        actingForTenant($tenant);

        $number = '5551'.random_int(100000, 999999);
        $this->numbers[$tenant] = $number;

        ChannelAccountModel::create([
            'channel' => 'whatsapp',
            'external_id' => $number,
            'webhook_secret' => "secreto-de-{$name}",
            'credentials' => ['access_token' => "token-de-{$name}"],
        ]);

        $conversation = ConversationModel::create([
            'channel' => 'whatsapp',
            'external_chat_id' => '58414'.random_int(1000000, 9999999),
            'customer_name' => "Cliente de {$name}",
        ]);

        $conversation->messages()->create([
            'direction' => 'in',
            'content' => "Mensaje para {$name}",
        ]);

        app(ChannelRouter::class)->register('whatsapp', $number, $tenant);
    }
});

it('each tenant sees only its own conversations', function (): void {
    actingForTenant($this->arepera);
    expect(ConversationModel::pluck('customer_name')->all())->toBe(['Cliente de Arepera']);

    actingForTenant($this->pizzeria);
    expect(ConversationModel::pluck('customer_name')->all())->toBe(['Cliente de Pizzería']);
});

it('another tenant\'s chat messages are not visible either', function (): void {
    actingForTenant($this->arepera);

    expect(MessageModel::count())->toBe(1)
        ->and(MessageModel::first()?->content)->toBe('Mensaje para Arepera');
});

it('one tenant\'s credentials do not exist for another', function (): void {
    // The worst thing this module could leak: with that token you write to
    // every one of the neighbour's customers in their name.
    actingForTenant($this->pizzeria);

    expect(ChannelAccountModel::count())->toBe(1)
        ->and(ChannelAccountModel::first()?->credential('access_token'))->toBe('token-de-Pizzería');
});

it('a WhatsApp number resolves to ONE tenant only', function (): void {
    $router = app(ChannelRouter::class);

    expect($router->tenantFor('whatsapp', $this->numbers[$this->arepera]))->toBe($this->arepera)
        ->and($router->tenantFor('whatsapp', $this->numbers[$this->pizzeria]))->toBe($this->pizzeria)
        // And one that belongs to nobody resolves to nobody, rather than to
        // whichever turns up first.
        ->and($router->tenantFor('whatsapp', '000000000000'))->toBeNull();
});

it('the same number cannot be registered in two tenants', function (): void {
    // Without the global unique index, a tenant could claim another's number
    // and start receiving their messages.
    expect(fn () => DB::table('channel_routes')->insert([
        'id' => (string) Str::uuid7(),
        'channel' => 'whatsapp',
        'external_id' => $this->numbers[$this->arepera],
        'tenant_id' => $this->pizzeria,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('with no tenant in context no conversation is visible', function (): void {
    withoutTenant();

    expect(DB::table('conversations')->count())->toBe(0)
        ->and(DB::table('messages')->count())->toBe(0)
        ->and(DB::table('channel_accounts')->count())->toBe(0);
});

it('a conversation cannot be slipped into another tenant\'s chat', function (): void {
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

it('the webhook phone book stores NO credentials', function (): void {
    /*
     * `channel_routes` is a platform table, read with no tenant in context and
     * therefore without RLS. That is why it may hold only enough to know whose
     * a number is — a token here would be within reach of any query that
     * forgets to filter.
     */
    $columns = DB::getSchemaBuilder()->getColumnListing('channel_routes');

    expect($columns)->not->toContain('credentials')
        ->not->toContain('webhook_secret')
        ->toContain('tenant_id');
});

afterEach(function (): void {
    Cache::flush();
});
