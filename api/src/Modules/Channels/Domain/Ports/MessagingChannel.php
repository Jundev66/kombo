<?php

declare(strict_types=1);

namespace Modules\Channels\Domain\Ports;

use Modules\Channels\Domain\ValueObjects\IncomingMessage;
use Modules\Channels\Domain\ValueObjects\Reply;

/**
 * Un canal por el que se habla con un cliente.
 *
 * El motor de conversación conoce **esto** y nada más. No sabe qué es un
 * `phone_number_id`, ni que Telegram llama `chat_id` a lo que Meta llama `wa_id`,
 * ni que uno firma con HMAC y el otro con una cabecera secreta.
 *
 * Cada adaptador aplica **sus propios límites**, no un mínimo común. Es la
 * diferencia entre que Telegram pueda enseñar ocho categorías de una vez y que
 * quede recortado a tres porque WhatsApp no da para más.
 */
interface MessagingChannel
{
    /** `whatsapp`, `telegram`. */
    public function code(): string;

    /**
     * Entrega lo que el motor quiso decir, como se pueda decir en este canal.
     *
     * Puede acabar en más de un mensaje: seis opciones en WhatsApp son dos
     * envíos de tres botones. Eso lo decide el adaptador.
     */
    public function send(string $chatId, Reply $reply): void;

    /**
     * ¿La petición viene de verdad del canal?
     *
     * Se comprueba **antes que nada**, y con comparación en tiempo constante.
     * Cualquiera puede hacer un POST a la dirección del webhook.
     */
    public function verifySignature(string $payload, array $headers, ?string $secret): bool;

    /**
     * Traduce lo que llegó a mensajes que el motor entienda.
     *
     * Un mismo webhook puede traer varios —los canales agrupan— y también
     * cosas que no son mensajes (avisos de entrega, de lectura), que se
     * descartan aquí y no más adelante.
     *
     * @return list<IncomingMessage>
     */
    public function parse(array $payload): array;
}
