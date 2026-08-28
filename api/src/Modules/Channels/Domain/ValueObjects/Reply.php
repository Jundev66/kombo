<?php

declare(strict_types=1);

namespace Modules\Channels\Domain\ValueObjects;

/**
 * Lo que el motor quiere decir, **sin saber por dónde va a salir**.
 *
 * Aquí está la decisión que hace que dos canales quepan en un solo motor: el
 * motor pide «muestra estas seis opciones» y **cada adaptador decide cómo**.
 * WhatsApp corta a tres botones por mensaje y a veinte caracteres por título;
 * Telegram admite teclados de la longitud que quieras.
 *
 * Si el motor conociera esos límites, escribiría para el más pobre de los dos
 * y Telegram quedaría igual de estrecho que WhatsApp sin razón. Y el día que
 * entre un tercer canal, habría que revisar el motor entero.
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
