<?php

declare(strict_types=1);

namespace Modules\Channels\Domain\ValueObjects;

/**
 * What the engine wants to say, without knowing where it will come out.
 *
 * This is what lets two channels share one engine: the engine asks for "show
 * these six options" and each adapter decides how. Knowing the limits here
 * would cramp Telegram down to WhatsApp's for no reason.
 */
final readonly class Reply
{
    /**
     * @param  list<ReplyOption>  $options
     */
    private function __construct(
        public string $text,
        public array $options = [],
        public ?string $imageUrl = null,
    ) {}

    public static function text(string $text): self
    {
        return new self($text);
    }

    /**
     * @param  list<ReplyOption>  $options
     */
    public static function withOptions(string $text, array $options): self
    {
        return new self($text, $options);
    }

    public static function withImage(string $text, string $imageUrl): self
    {
        return new self($text, [], $imageUrl);
    }

    public function hasOptions(): bool
    {
        return $this->options !== [];
    }
}
