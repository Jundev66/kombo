<?php

declare(strict_types=1);

namespace Modules\Channels\Domain\ValueObjects;

/**
 * What a customer wrote — or tapped — already translated. The only format the
 * engine sees; WhatsApp and Telegram both become this.
 */
final readonly class IncomingMessage
{
    public function __construct(
        /** The message id ON the channel. This is what deduplicates. */
        public string $externalId,

        /** Who is being talked to: the phone number or the `chat_id`. */
        public string $chatId,

        /** What they wrote, if they wrote. */
        public string $text = '',

        /** What they tapped, if they tapped. It beats the text. */
        public ?string $optionId = null,

        public ?string $senderName = null,
        public ?string $senderPhone = null,
    ) {}

    /**
     * What the engine has to interpret: the button if there was one, otherwise
     * the text. The button wins because it admits no doubt.
     */
    public function intent(): string
    {
        return $this->optionId ?? trim($this->text);
    }
}
