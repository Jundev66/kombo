<?php

declare(strict_types=1);

namespace Platform\Tenancy;

/**
 * En qué situación está un negocio.
 *
 * La distinción que importa es entre **poder entrar** y **poder escribir**. Un
 * negocio que dejó de pagar no se queda fuera de golpe: entra, consulta y
 * exporta todo durante el período de gracia. Borrarle el acceso a sus propios
 * datos a alguien que confió en el sistema no es una palanca de cobro
 * aceptable.
 */
enum TenantStatus: string
{
    /** Probando. Entra y opera con normalidad. */
    case Trial = 'trial';

    /** Al día. */
    case Active = 'active';

    /** Se le venció y está en gracia: entra, opera, y ve el aviso. */
    case PastDue = 'past_due';

    /** Suspendido: entra, pero sólo lee y exporta. */
    case Suspended = 'suspended';

    /** Cerrado. No entra. */
    case Closed = 'closed';

    public function allowsAccess(): bool
    {
        return $this !== self::Closed;
    }

    public function allowsWrites(): bool
    {
        return in_array($this, [self::Trial, self::Active, self::PastDue], true);
    }

    /** Para avisar en la interfaz antes de que se convierta en un problema. */
    public function needsAttention(): bool
    {
        return in_array($this, [self::PastDue, self::Suspended], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'En prueba',
            self::Active => 'Al día',
            self::PastDue => 'Vencido',
            self::Suspended => 'Suspendido',
            self::Closed => 'Cerrado',
        };
    }
}
