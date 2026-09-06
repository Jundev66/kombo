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
 * The bot. Four screens and no interpretation.
 *
 * Buttons, not free language: a model that interprets what people write gets it
 * right almost always, and that "almost" is mis-taken orders somebody pays for.
 *
 * The basket is assembled in the portal — picking add-ons inside a chat is
 * twenty messages nobody finishes. Option ids are short by construction (`c:2`,
 * `p:5`) because Telegram's `callback_data` stops at 64 bytes and a long one
 * fails without saying anything.
 */
final class ConversationEngine
{
    /** How many options are offered at once. More is a list to read. */
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
         * If a person took the conversation over, the bot goes quiet. Without
         * this escape hatch the customer asks for a human and gets an automated
         * menu on top of what they are writing.
         */
        if ($this->stillTakenByAHuman($conversation)) {
            return [];
        }

        $intent = $message->intent();

        return match (true) {
            $intent === 'menu' || $intent === '' => $this->mainMenu($conversation),
            $intent === 'catalog' => $this->categories($conversation, 0),
            str_starts_with($intent, 'cat:') => $this->categories($conversation, (int) substr($intent, 4)),
            str_starts_with($intent, 'c:') => $this->products($conversation, (int) substr($intent, 2)),
            str_starts_with($intent, 'p:') => $this->product($conversation, (int) substr($intent, 2)),
            $intent === 'order' => $this->lastOrder($conversation),
            $intent === 'human' => $this->handOver($conversation),
            default => $this->mainMenu($conversation),
        };
    }

    /**
     * @return list<Reply>
     */
    private function mainMenu(ConversationModel $conversation): array
    {
        $tenant = $this->context->current();
        $greeting = trim((string) $this->capabilities->get()->setting('channels.greeting', ''));

        $conversation->update(['state' => 'menu', 'state_data' => []]);

        return [Reply::withOptions(
            $greeting !== '' ? $greeting : "¡Hola! Somos {$tenant->name}. ¿Qué quieres hacer?",
            [
                new ReplyOption('catalog', 'Ver la carta'),
                new ReplyOption('order', 'Mi pedido'),
                new ReplyOption('human', 'Hablar con alguien'),
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
            // With no categories the products are shown directly: a small menu has no
            // business inventing sections.
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
            // The REAL ids are stored here and only the index travels, so the button
            // fits in 64 bytes on any channel.
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
            // What has run out is not offered: showing it invites the question of
            // whether there really is none left.
            ->reject(fn (ProductModel $p): bool => $p->track_stock && ($p->stock_qty ?? 0) <= 0)
            ->values();

        if ($products->isEmpty()) {
            return [Reply::withOptions('Ahí no queda nada por ahora.', [
                new ReplyOption('catalog', 'Ver otra sección'),
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

        $list = $products
            ->map(fn (ProductModel $p): string => "• {$p->name} — ".$this->price($p->price_cents))
            ->implode("\n");

        return [Reply::withOptions(
            $list."\n\nToca uno para verlo, o pide desde la carta completa:\n".$this->portalLink(),
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

        $text = "*{$product->name}*\n".$this->price($product->price_cents);

        if ($product->description !== null && $product->description !== '') {
            $text .= "\n\n{$product->description}";
        }

        // Where the chat ends and the portal begins. Assembling the order with its
        // add-ons is a screen's job, not a conversation's.
        $text .= "\n\nPara pedirlo:\n".$this->portalLink();

        $reply = $product->photo_url !== null
            ? Reply::withImage($text, $product->photo_url)
            : Reply::text($text);

        return [$reply, Reply::withOptions('¿Algo más?', [
            new ReplyOption('catalog', 'Ver la carta'),
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
                [new ReplyOption('catalog', 'Ver la carta'), new ReplyOption('human', 'Hablar con alguien')],
            )];
        }

        // The tracking link: the screen that already tells this story properly, and
        // repeating the state machine here would be two copies.
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
     * Still taken by a person?
     *
     * It releases itself after a while, or the manager helps somebody, goes off
     * to close up, and the bot stays mute for that customer forever.
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

        // The bolívar alongside, which is what people decide on.
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
     * The link to THIS tenant's portal.
     *
     * From settings rather than `url()`: a notice is sent from the queue, where
     * there is no request and no `Host` to infer anything from.
     */
    private function portalLink(string $path = '/'): string
    {
        return PortalLink::forTenant($this->context->current()->slug, $path);
    }
}
