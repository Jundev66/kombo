<?php

declare(strict_types=1);

namespace Modules\Portal\Domain\Exceptions;

use Shared\Domain\Exceptions\UserError;

/**
 * El portal no acepta este pedido, y le dice al cliente por qué.
 *
 * 422 y no 400: los datos están bien formados, lo que pasa es que el negocio
 * está cerrado, o no reparte ahí, o falta poco para el mínimo. Todo eso tiene
 * arreglo desde la pantalla, y el mensaje tiene que decir cuál.
 */
final class PortalRefused extends UserError
{
    public function __construct(string $message, private readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    /** A qué campo del formulario apunta, para pintarlo donde toca. */
    public function field(): ?string
    {
        return $this->field;
    }

    public static function closed(): self
    {
        return new self('Ahora mismo está cerrado. Mira el horario y vuelve cuando abramos.');
    }

    public static function serviceNotOffered(string $service): self
    {
        return new self(
            $service === 'delivery'
                ? 'Este negocio no está repartiendo a domicilio.'
                : 'Este negocio no acepta pedidos para buscar.',
            'service_type',
        );
    }

    public static function unknownZone(): self
    {
        return new self('No repartimos a esa zona. Elige otra de la lista.', 'delivery_zone_id');
    }

    public static function belowMinimum(string $minimum): self
    {
        return new self("Para que salga el reparto el pedido tiene que llegar a {$minimum}.", 'items');
    }

    public static function paymentNotAccepted(): self
    {
        return new self('Ese modo de pago no está disponible aquí.', 'payment_method');
    }

    public static function addressMissing(): self
    {
        return new self('Falta la dirección: sin ella no hay a dónde llevarlo.', 'delivery_address');
    }
}
