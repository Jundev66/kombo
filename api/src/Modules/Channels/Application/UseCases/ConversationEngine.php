<?php

declare(strict_types=1);

namespace Modules\Channels\Application\UseCases;

use App\Models\Catalog\CategoryModel;
use App\Models\Catalog\ProductModel;
use App\Models\Channels\ConversationModel;
use App\Models\Orders\OrderModel;
use Illuminate\Support\Facades\DB;
use Modules\Channels\Domain\ValueObjects\IncomingMessage;
use Modules\Channels\Domain\ValueObjects\Reply;
use Modules\Channels\Domain\ValueObjects\ReplyOption;
use Modules\Channels\Infrastructure\Services\PortalLink;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Tenancy\TenantContext;
use Shared\Domain\ValueObjects\Money;

/**
 * El bot. Cuatro pantallas y ninguna interpretación.
 *
 * **Botones, no lenguaje libre.** Un modelo que interpreta lo que la gente
 * escribe acierta casi siempre, y ese «casi» son pedidos mal tomados que
 * alguien tiene que pagar. Aquí el cliente toca y el sistema sabe exactamente
 * qué quiso decir; lo único que se lee del texto libre es para volver al menú.
 *
 * **El carrito se arma en el portal, no aquí.** Elegir agregados dentro de un
 * chat son veinte mensajes que nadie termina. El chat sirve para descubrir la
 * carta, recibir avisos, y hablar con una persona cuando hace falta.
 *
 * Los identificadores de las opciones son **cortos por construcción** (`c:2`,
 * `p:5`) y se resuelven contra lo que quedó guardado en la conversación: el
 * `callback_data` de Telegram no pasa de 64 bytes, y un identificador largo
 * falla sin decir nada.
 */
final class ConversationEngine
{
    /** Cuántas opciones se ofrecen de una vez. Más es una lista para leer. */
    private const PAGE = 6;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CurrentCapabilities $capabilities,
    ) {}

    /**
     * @return list<Reply>
     */
    public function respond(ConversationModel $conversation, IncomingMessage $message): array
    {
        /*
         * Si una persona tomó la conversación, el bot se calla.
         *
         * Es la salida sin la que cualquier bot es un muro: el cliente pidió
         * hablar con alguien, y recibir un menú automático encima de lo que
         * está escribiendo con el encargado sería justo lo contrario.
         */
        if ($this->stillTakenByAHuman($conversation)) {
            return [];
        }

        $intent = $message->intent();

        return match (true) {
            $intent === 'menu' || $intent === '' => $this->mainMenu($conversation),
            $intent === 'carta' => $this->categories($conversation, 0),
            str_starts_with($intent, 'cat:') => $this->categories($conversation, (int) substr($intent, 4)),
            str_starts_with($intent, 'c:') => $this->products($conversation, (int) substr($intent, 2)),
            str_starts_with($intent, 'p:') => $this->product($conversation, (int) substr($intent, 2)),
            $intent === 'pedido' => $this->lastOrder($conversation),
            $intent === 'persona' => $this->handOver($conversation),
            default => $this->mainMenu($conversation),
        };
    }

    /**
     * @return list<Reply>
     */
    private function mainMenu(ConversationModel $conversation): array
    {
        $tenant = $this->context->current();
        $saludo = trim((string) $this->capabilities->get()->setting('channels.greeting', ''));

        $conversation->update(['state' => 'menu', 'state_data' => []]);

        return [Reply::withOptions(
            $saludo !== '' ? $saludo : "¡Hola! Somos {$tenant->name}. ¿Qué quieres hacer?",
            [
                new ReplyOption('carta', 'Ver la carta'),
                new ReplyOption('pedido', 'Mi pedido'),
                new ReplyOption('persona', 'Hablar con alguien'),
            ],
        )];
    }

    /**
     * @return list<Reply>
     */
    private function categories(ConversationModel $conversation, int $page): array
    {
        $categories = CategoryModel::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($categories->isEmpty()) {
            // Sin categorías se enseñan los productos directamente: una carta
            // pequeña no tiene por qué inventarse secciones.
            return $this->products($conversation, -1);
        }

        $slice = $categories->slice($page * self::PAGE, self::PAGE)->values();

        $options = $slice->map(fn (CategoryModel $c, int $i): ReplyOption => new ReplyOption(
            'c:'.($page * self::PAGE + $i),
            $c->name,
        ))->all();

        if ($categories->count() > ($page + 1) * self::PAGE) {
            $options[] = new ReplyOption('cat:'.($page + 1), 'Ver más');
        }

        $conversation->update([
            'state' => 'categories',
            // Se guardan los identificadores REALES aquí, y por el canal sólo
            // viaja el índice. Así el botón cabe en 64 bytes en cualquier canal.
            'state_data' => ['categories' => $categories->pluck('id')->all()],
        ]);

        return [Reply::withOptions('¿Qué te provoca?', $options)];
    }

    /**
     * @return list<Reply>
     */
    private function products(ConversationModel $conversation, int $categoryIndex): array
    {
        $ids = $conversation->state_data['categories'] ?? [];
        $categoryId = $categoryIndex >= 0 ? ($ids[$categoryIndex] ?? null) : null;

        $products = ProductModel::query()
            ->where('is_active', true)
            ->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(self::PAGE)
            ->get()
            // Lo agotado no se ofrece: enseñarlo invita a preguntar si de
            // verdad no queda.
            ->reject(fn (ProductModel $p): bool => $p->track_stock && ($p->stock_qty ?? 0) <= 0)
            ->values();

        if ($products->isEmpty()) {
            return [Reply::withOptions('Ahí no queda nada por ahora.', [
                new ReplyOption('carta', 'Ver otra sección'),
                new ReplyOption('menu', 'Volver'),
            ])];
        }

        $conversation->update([
            'state' => 'products',
            'state_data' => [
                ...$conversation->state_data ?? [],
                'products' => $products->pluck('id')->all(),
            ],
        ]);

        $lista = $products
            ->map(fn (ProductModel $p): string => "• {$p->name} — ".$this->price($p->price_cents))
            ->implode("\n");

        return [Reply::withOptions(
            $lista."\n\nToca uno para verlo, o pide desde la carta completa:\n".$this->portalLink(),
            [
                ...$products->map(fn (ProductModel $p, int $i): ReplyOption => new ReplyOption(
                    "p:{$i}",
                    $p->name,
                ))->all(),
                new ReplyOption('menu', 'Volver'),
            ],
        )];
    }

    /**
     * @return list<Reply>
     */
    private function product(ConversationModel $conversation, int $index): array
    {
        $ids = $conversation->state_data['products'] ?? [];
        $product = ProductModel::find($ids[$index] ?? null);

        if ($product === null) {
            return $this->mainMenu($conversation);
        }

        $texto = "*{$product->name}*\n".$this->price($product->price_cents);

        if ($product->description !== null && $product->description !== '') {
            $texto .= "\n\n{$product->description}";
        }

        // **Aquí acaba el chat y empieza el portal.** Armar el pedido con sus
        // agregados es cosa de una pantalla, no de una conversación.
        $texto .= "\n\nPara pedirlo:\n".$this->portalLink();

        $reply = $product->photo_url !== null
            ? Reply::withImage($texto, $product->photo_url)
            : Reply::text($texto);

        return [$reply, Reply::withOptions('¿Algo más?', [
            new ReplyOption('carta', 'Ver la carta'),
            new ReplyOption('menu', 'Volver'),
        ])];
    }

    /**
     * @return list<Reply>
     */
    private function lastOrder(ConversationModel $conversation): array
    {
        $order = OrderModel::query()
            ->where('customer_phone', $conversation->customer_phone ?? $conversation->external_chat_id)
            ->latest('created_at')
            ->first();

        if ($order === null) {
            return [Reply::withOptions(
                'No encontramos ningún pedido tuyo. Si lo hiciste con otro número, escríbenos.',
                [new ReplyOption('carta', 'Ver la carta'), new ReplyOption('persona', 'Hablar con alguien')],
            )];
        }

        // El enlace al seguimiento: es la pantalla que ya cuenta esto bien, y
        // repetir aquí la máquina de estados sería tenerla en dos sitios.
        return [Reply::withOptions(
            "Tu pedido #{$order->number} va así: {$order->status->label()}.\n\n".
            'Puedes seguirlo aquí:'."\n".$this->portalLink("/p/{$order->public_token}"),
            [new ReplyOption('menu', 'Volver')],
        )];
    }

    /**
     * @return list<Reply>
     */
    private function handOver(ConversationModel $conversation): array
    {
        $conversation->update([
            'is_human_takeover' => true,
            'takeover_at' => now(),
            'state' => 'human',
        ]);

        return [Reply::text(
            'Listo, ya le avisamos a alguien del local. Escribe aquí lo que necesites.',
        )];
    }

    /**
     * ¿Sigue tomada por una persona?
     *
     * Se suelta sola pasado un rato: sin eso, el encargado atiende a alguien,
     * se va a cerrar, y el bot queda mudo para ese cliente **para siempre**.
     */
    private function stillTakenByAHuman(ConversationModel $conversation): bool
    {
        if (! $conversation->is_human_takeover) {
            return false;
        }

        $minutes = (int) $this->capabilities->get()->setting('channels.takeover_minutes', 60);

        if ($conversation->takeover_at?->addMinutes($minutes)->isFuture() === true) {
            return true;
        }

        $conversation->update(['is_human_takeover' => false, 'takeover_at' => null, 'state' => 'idle']);

        return false;
    }

    private function price(int $cents): string
    {
        $usd = '$'.Money::fromCents($cents)->format();
        $rate = $this->rate();

        // El bolívar al lado, que es con lo que la gente decide.
        return $rate === null
            ? $usd
            : $usd.' (Bs '.number_format($cents * $rate / 100, 2, ',', '.').')';
    }

    private function rate(): ?float
    {
        $rate = DB::table('exchange_rates')->orderByDesc('effective_date')->value('rate');

        return $rate === null ? null : (float) $rate;
    }

    /**
     * El enlace al portal de ESTE negocio.
     *
     * Sale de la configuración y no de `url()` a propósito: un aviso se manda
     * desde la cola, donde no hay petición ni `Host` del que deducir nada.
     */
    private function portalLink(string $path = '/'): string
    {
        return PortalLink::forTenant($this->context->current()->slug, $path);
    }
}
