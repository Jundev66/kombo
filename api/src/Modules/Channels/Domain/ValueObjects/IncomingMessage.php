<?php

declare(strict_types=1);

namespace Modules\Channels\Domain\ValueObjects;

/**
 * Lo que escribió —o tocó— un cliente, ya traducido.
 *
 * Es el único formato que ve el motor. WhatsApp manda una cosa y Telegram otra
 * bastante distinta; a partir de aquí, las dos son esto.
 */
final readonly class IncomingMessage
{
    public function __construct(
        /** El identificador del mensaje EN el canal. Con esto se deduplica. */
        public string $externalId,

        /** Con quién se habla: el teléfono o el `chat_id`. */
        public string $chatId,

        /** Lo que escribió, si escribió. */
        public string $text = '',

        /** Lo que tocó, si tocó un botón. Manda sobre el texto. */
        public ?string $optionId = null,

        public ?string $senderName = null,
        public ?string $senderPhone = null,
    ) {}

    /**
     * Lo que el motor tiene que interpretar.
     *
     * Si tocó un botón, eso; si no, lo que escribió. El botón manda porque es
     * lo único que no admite dudas: el texto libre puede ser cualquier cosa.
     */
    public function intent(): string
    {
        return $this->optionId ?? trim($this->text);
    }
}
