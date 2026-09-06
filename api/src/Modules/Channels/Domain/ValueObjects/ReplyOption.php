<?php

declare(strict_types=1);

namespace Modules\Channels\Domain\ValueObjects;

/**
 * An option the customer can tap.
 *
 * The `id` comes back when they tap it and has to be SHORT: Telegram's
 * `callback_data` stops at 64 bytes, and it fails in the worst way — the
 * message is accepted and the button simply does nothing.
 */
final readonly class ReplyOption
{
    public const MAX_ID_BYTES = 64;

    public function __construct(
        public string $id,
        public string $label,
    ) {}

    public function fitsEverywhere(): bool
    {
        return strlen($this->id) <= self::MAX_ID_BYTES;
    }

    /**
     * The title, trimmed to what fits rather than rejected: a product called
     * "Arepa de pernil con queso amarillo" is not a system error, just a name
     * that does not fit on a button.
     */
    public function labelWithin(int $characters): string
    {
        if (mb_strlen($this->label) <= $characters) {
            return $this->label;
        }

        return mb_substr($this->label, 0, $characters - 1).'…';
    }
}
