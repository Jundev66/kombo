<?php

declare(strict_types=1);

namespace Modules\Channels\Infrastructure\Services;

use App\Models\Channels\ChannelAccountModel;
use Modules\Channels\Domain\Ports\MessagingChannel;
use Modules\Channels\Infrastructure\Adapters\TelegramChannel;
use Modules\Channels\Infrastructure\Adapters\WhatsAppChannel;
use RuntimeException;

/**
 * From a stored account to the adapter that can talk through it.
 *
 * The only place where the list of channels appears. Adding one is a new class
 * and a line here; nothing else knows.
 */
final class ChannelFactory
{
    public function for(ChannelAccountModel $account): MessagingChannel
    {
        return match ($account->channel) {
            'whatsapp' => new WhatsAppChannel($account),
            'telegram' => new TelegramChannel($account),
            default => throw new RuntimeException("No hay adaptador para el canal «{$account->channel}»."),
        };
    }

    /**
     * The channels the system knows how to handle.
     *
     * @return list<string>
     */
    public function available(): array
    {
        return ['whatsapp', 'telegram'];
    }
}
