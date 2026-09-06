<?php

declare(strict_types=1);

namespace Platform\Tenancy;

/**
 * What situation a tenant is in.
 *
 * The distinction that matters is between getting IN and being able to WRITE.
 * A tenant that stopped paying is not locked out at once: it reads and exports
 * everything through the grace period. Cutting someone off from their own data
 * is not an acceptable collection tactic.
 */
enum TenantStatus: string
{
    /** Trialling. Signs in and operates normally. */
    case Trial = 'trial';

    /** Paid up. */
    case Active = 'active';

    /** Overdue and in grace: signs in, operates, and sees the warning. */
    case PastDue = 'past_due';

    /** Suspended: signs in, but only reads and exports. */
    case Suspended = 'suspended';

    /** Closed. No entry. */
    case Closed = 'closed';

    public function allowsAccess(): bool
    {
        return $this !== self::Closed;
    }

    public function allowsWrites(): bool
    {
        return in_array($this, [self::Trial, self::Active, self::PastDue], true);
    }

    /** To warn in the UI before it becomes a problem. */
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
