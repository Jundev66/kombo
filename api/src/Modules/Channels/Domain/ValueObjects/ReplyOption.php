<?php

declare(strict_types=1);

namespace Modules\Channels\Domain\ValueObjects;

/**
 * Una opción que el cliente puede tocar.
 *
 * El `id` es lo que vuelve cuando la toca, y **tiene que ser corto**: el
 * `callback_data` de Telegram no pasa de **64 bytes**. Un identificador del
 * tipo `ver_categoria_01a04b2c-...-...` no cabe, y lo peor es cómo falla —
 * Telegram acepta el mensaje y el botón sencillamente no hace nada, sin error
 * en ningún sitio.
 *
 * Por eso los identificadores de aquí son cortos por construcción (`c:3`,
 * `p:7`) y el índice se resuelve contra lo que el motor guardó en el estado de
 * la conversación.
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
     * El título, recortado a lo que quepa.
     *
     * Se recorta con puntos suspensivos y no se rechaza: que un producto se
     * llame «Arepa de pernil con queso amarillo» no puede ser un error del
     * sistema, es sólo un nombre que no cabe en un botón.
     */
    public function labelWithin(int $characters): string
    {
        if (mb_strlen($this->label) <= $characters) {
            return $this->label;
        }

        return mb_substr($this->label, 0, $characters - 1).'…';
    }
}
