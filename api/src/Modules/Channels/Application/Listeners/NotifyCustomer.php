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
 * «Ya está listo».
 *
 * Es el mensaje que más trabajo ahorra de todo el sistema. Un cliente que no
 * sabe cómo va su pedido llama, y esa llamada se la come quien está cocinando
 * — que además tiene que dejar la plancha para contestarla.
 *
 * Va **por evento**, igual que la cocina, y por la misma razón: `Orders` no
 * sabe que los canales existen. Se puede borrar este módulo entero y los
 * pedidos siguen funcionando; sólo que nadie avisa a nadie.
 *
 * Y va **en la cola**: que WhatsApp esté lento no puede hacer que el cocinero
 * espere para marcar la comanda como lista.
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

            $texto = self::messageFor($event->status);

            // Hay estados que no se avisan: «confirmado» y «en preparación» son
            // lo mismo para quien espera, y dos mensajes seguidos diciendo casi
            // igual se leen como spam.
            if ($texto === null) {
                return;
            }

            $order = OrderModel::find($event->orderId);

            if ($order === null || $order->customer_phone === null) {
                return;
            }

            // Se le escribe por donde ÉL escribió. Buscar su teléfono en todos
            // los canales y mandarle por dos sería duplicarle el aviso.
            $conversation = ConversationModel::where('customer_phone', $order->customer_phone)
                ->orWhere('external_chat_id', $order->customer_phone)
                ->latest('last_message_at')
                ->first();

            if ($conversation === null) {
                // Pidió por el portal sin haber escrito nunca al bot. No hay
                // por dónde avisarle, y está bien: el portal ya se lo enseña.
                return;
            }

            $account = ChannelAccountModel::where('channel', $conversation->channel)->first();

            if ($account === null || ! $account->is_active) {
                return;
            }

            $seguimiento = PortalLink::forTenant($tenant->slug, "/p/{$order->public_token}");

            $cuerpo = "Pedido #{$order->number}: {$texto}\n\n{$seguimiento}";

            $this->channels->for($account)->send($conversation->external_chat_id, Reply::text($cuerpo));

            MessageModel::create([
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'content' => $cuerpo,
                'message_type' => 'notification',
                'metadata' => ['order_id' => $order->id, 'status' => $event->status],
            ]);
        });
    }

    /**
     * Qué se dice en cada estado, y qué NO se dice.
     *
     * En palabras del cliente, no del negocio. Y sólo en los momentos que le
     * cambian algo: si se avisa de todo, deja de leerlos.
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
