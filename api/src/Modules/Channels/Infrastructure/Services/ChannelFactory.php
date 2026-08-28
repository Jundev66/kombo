<?php

declare(strict_types=1);

namespace Modules\Channels\Infrastructure\Services;

use App\Models\Channels\ChannelAccountModel;
use Modules\Channels\Domain\Ports\MessagingChannel;
use Modules\Channels\Infrastructure\Adapters\TelegramChannel;
use Modules\Channels\Infrastructure\Adapters\WhatsAppChannel;
use RuntimeException;

/**
 * De una cuenta guardada al adaptador que sabe hablar por ella.
 *
 * Es el único sitio del sistema donde aparece la lista de canales que existen.
 * Añadir uno es una clase nueva y una línea aquí; nada más lo sabe.
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
     * Los canales que el sistema sabe manejar.
     *
     * @return list<string>
     */
    public function available(): array
    {
        return ['whatsapp', 'telegram'];
    }
}
