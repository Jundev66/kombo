<?php

declare(strict_types=1);

namespace Platform\Audit;

/**
 * Who did something, when that is not who authenticated the request.
 *
 * It happens constantly: the till is authenticated with the DEVICE token, but
 * whoever took payment is Ana, who entered her PIN. The log has to say "Ana".
 *
 * A type rather than two loose arguments: an id without a name cannot be read,
 * and a name without an id cannot be traced.
 */
final readonly class Actor
{
    public function __construct(
        public string $userId,
        public string $userName,
    ) {}
}
