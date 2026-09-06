<?php

declare(strict_types=1);

namespace Modules\Channels;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * WhatsApp and Telegram: where people arrive from. For a food business in
 * Venezuela, WhatsApp is the channel — people write to the same number they
 * write to their aunt on.
 *
 * The bot does not sell: it shows the menu and sends people to the portal. And
 * it carries no AI, on purpose — buttons mean the system knows exactly what the
 * customer meant.
 */
final class ChannelsModule extends ModuleManifest
{
    public function code(): string
    {
        return 'channels';
    }

    public function name(): string
    {
        return 'WhatsApp y Telegram';
    }

    public function description(): string
    {
        return 'Que el cliente vea la carta y reciba avisos por donde ya escribe.';
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        // No menu, nothing to show; no portal, nowhere to send them to order.
        return ['catalog', 'orders', 'portal'];
    }

    public function routes(): ?string
    {
        return __DIR__.'/Interfaces/Http/Routes/api.php';
    }

    public function migrations(): ?string
    {
        return __DIR__.'/Infrastructure/Migrations';
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return [
            // Connecting a channel means pasting a token that can write to every
            // customer in the tenant's name. Not a preference.
            'channels.manage',

            // Reading the chats and replying by hand when the bot cannot cope.
            'channels.view',
            'channels.reply',
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            // The greeting. The owner writes it once, and it is the first thing
            // everyone who writes to the tenant reads.
            'greeting' => Setting::text('')->maxLength(300),

            /*
             * Announcing every state change. On by default: a customer who
             * gets "it's ready" does not ring to ask, and that call is absorbed
             * by whoever is cooking.
             */
            'notify_status' => Setting::bool(true),

            /*
             * After how many silent minutes a conversation taken over by a
             * person is released — or the manager goes off to close up and the
             * bot stays mute for that customer forever.
             */
            'takeover_minutes' => Setting::int(60)->min(5)->max(1440),
        ];
    }
}
