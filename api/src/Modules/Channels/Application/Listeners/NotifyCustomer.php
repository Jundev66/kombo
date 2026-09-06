<?php

declare(strict_types=1);

namespace Modules\Channels\Application\Listeners;

use App\Models\Channels\ChannelAccountModel;
use App\Models\Channels\ConversationModel;
use App\Models\Channels\MessageModel;
use App\Models\Orders\OrderModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Channels\Domain\ValueObjects\Reply;
use Modules\Channels\Infrastructure\Services\ChannelFactory;
use Modules\Channels\Infrastructure\Services\PortalLink;
use Modules\Orders\Domain\Events\OrderAdvanced;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Tenancy\Tenant;
use Platform\Tenancy\TenantSession;

/**
 * "It's ready" — the message that saves the most work in the system. A customer
 * who does not know how their order is going rings, and that call is absorbed
 * by whoever is cooking.
 *
 * By event, like the kitchen: `Orders` does not know channels exist. And on the
 * queue, so a slow WhatsApp cannot make the cook wait to mark a ticket ready.
 */
final class NotifyCustomer implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(
        private readonly TenantSession $session,
        private readonly ChannelFactory $channels,
        private readonly CurrentCapabilities $capabilities,
    ) {}

    public function handle(OrderAdvanced $event): void
    {
        $this->session->within($event->tenantId, function (Tenant $tenant) use ($event): void {
            $caps = $this->capabilities->get();

            if (! $caps->hasModule('channels') || $caps->setting('channels.notify_status', true) !== true) {
                return;
            }

            $text = self::messageFor($event->status);

            // Some states are not announced: "confirmed" and "preparing" are the same
            // thing to whoever is waiting, and two near-identical messages read as spam.
            if ($text === null) {
                return;
            }

            $order = OrderModel::find($event->orderId);

            if ($order === null || $order->customer_phone === null) {
                return;
            }

            // They are written to wherever THEY wrote from. Looking their number up
            // across every channel would double the notice.
            $conversation = ConversationModel::where('customer_phone', $order->customer_phone)
                ->orWhere('external_chat_id', $order->customer_phone)
                ->latest('last_message_at')
                ->first();

            if ($conversation === null) {
                // They ordered through the portal without ever writing to the bot. Nowhere
                // to tell them, and that is fine: the portal shows it.
                return;
            }

            $account = ChannelAccountModel::where('channel', $conversation->channel)->first();

            if ($account === null || ! $account->is_active) {
                return;
            }

            $tracking = PortalLink::forTenant($tenant->slug, "/p/{$order->public_token}");

            $body = "Pedido #{$order->number}: {$text}\n\n{$tracking}";

            $this->channels->for($account)->send($conversation->external_chat_id, Reply::text($body));

            MessageModel::create([
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'content' => $body,
                'message_type' => 'notification',
                'metadata' => ['order_id' => $order->id, 'status' => $event->status],
            ]);
        });
    }

    /**
     * What is said in each state, and what is not.
     *
     * In the customer's words, and only at the moments that change something
     * for them: notify about everything and they stop reading.
     */
    private static function messageFor(string $status): ?string
    {
        return match ($status) {
            'confirmed' => 'lo estamos haciendo. 🍳',
            'ready' => '¡listo! Ya puedes venir a buscarlo.',
            'out_for_delivery' => 'va en camino. 🛵',
            'delivered' => 'entregado. ¡Gracias!',
            default => null,
        };
    }
}
