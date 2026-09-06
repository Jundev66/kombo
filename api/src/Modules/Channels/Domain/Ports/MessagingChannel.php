<?php

declare(strict_types=1);

namespace Modules\Channels\Domain\Ports;

use Modules\Channels\Domain\ValueObjects\IncomingMessage;
use Modules\Channels\Domain\ValueObjects\Reply;

/**
 * A channel a customer is talked to over.
 *
 * The conversation engine knows this and nothing else — not what a
 * `phone_number_id` is, nor that one channel signs with HMAC and the other with
 * a header. Each adapter applies its own limits rather than a lowest common
 * denominator, so Telegram is not cramped down to WhatsApp's three buttons.
 */
interface MessagingChannel
{
    /** `whatsapp`, `telegram`. */
    public function code(): string;

    /**
     * Delivers what the engine meant, however this channel can say it. Six
     * options on WhatsApp become two sends of three buttons; the adapter
     * decides.
     */
    public function send(string $chatId, Reply $reply): void;

    /**
     * Does the request really come from the channel? Checked before anything
     * else, in constant time: anyone can POST to a webhook address.
     */
    public function verifySignature(string $payload, array $headers, ?string $secret): bool;

    /**
     * Translates what arrived into messages the engine understands.
     *
     * One webhook may carry several, and also things that are not messages
     * (delivery and read receipts), discarded here rather than further along.
     *
     * @return list<IncomingMessage>
     */
    public function parse(array $payload): array;
}
