<?php

declare(strict_types=1);

namespace Platform\Auth;

/**
 * The base roles a tenant receives at sign-up.
 *
 * Permissions of modules the tenant does not have are filtered out on apply, so
 * one catalog serves a portal-only stall and a shop with till, kitchen and
 * delivery. What does matter is that each permission exists in some manifest —
 * one that does not is silently dropped, and nobody finds out until they try.
 */
final class RoleCatalog
{
    /** Can carry it out alone. */
    private const SI = false;

    /** Can START it; carrying it out needs the PIN of someone who can. */
    private const SOLICITA = true;

    /**
     * @return array<string, array{name: string, is_owner: bool, permissions: array<string, bool>}>
     */
    public static function all(): array
    {
        return [
            'owner' => [
                'name' => 'Dueño',
                'is_owner' => true,
                // Deliberately empty: an owner carries no permission rows. They resolve to
                // `['*']`, expanded against the modules switched on TODAY.
                'permissions' => [],
            ],

            'manager' => [
                'name' => 'Encargado',
                'is_owner' => false,
                'permissions' => [
                    'orders.view' => self::SI,
                    'orders.create' => self::SI,
                    'orders.confirm' => self::SI,
                    'orders.cancel' => self::SI,
                    'payments.confirm' => self::SI,
                    'kitchen.view' => self::SI,
                    'kitchen.update' => self::SI,
                    'counter.sell' => self::SI,
                    'counter.discount' => self::SI,
                    'counter.void' => self::SI,
                    'notes.issue' => self::SI,
                    'notes.reprint' => self::SI,
                    'catalog.view' => self::SI,
                    'catalog.manage' => self::SI,
                    'catalog.change_price' => self::SI,
                    'delivery.manage' => self::SI,
                    // Setting the zones without being able to open the deliveries board was
                    // the same incoherence as the opening hours: configuring delivery blind.
                    'delivery.view_own' => self::SI,
                    'delivery.mark_delivered' => self::SI,
                    'customers.view' => self::SI,
                    'customers.manage' => self::SI,
                    'reports.view_sales' => self::SI,

                    // The manager runs the shop when the owner is away, so they reach
                    // everything touched during a day: hours, rate, bot and team.
                    'settings.manage' => self::SI,
                    'channels.view' => self::SI,
                    'audit.view' => self::SI,

                    // They do hold `users.manage` (KMB-0006). The obvious hole — whoever
                    // creates users creates themselves an owner account — is closed in
                    // `TeamController`, not by removing the permission, which would also
                    // block signing up the new cook on a Saturday.
                    'users.manage' => self::SI,
                ],
            ],

            'counter' => [
                'name' => 'Mostrador',
                'is_owner' => false,
                'permissions' => [
                    'counter.sell' => self::SI,
                    'notes.issue' => self::SI,
                    'notes.reprint' => self::SI,
                    'orders.view' => self::SI,
                    'orders.create' => self::SI,
                    'payments.confirm' => self::SI,
                    'catalog.view' => self::SI,
                    'customers.view' => self::SI,
                    'kitchen.view' => self::SI,

                    // These are the natural ways to get money out of the till, so they are
                    // started but not carried out alone.
                    'counter.void_request' => self::SOLICITA,
                    'counter.discount_request' => self::SOLICITA,
                ],
            ],

            'kitchen' => [
                'name' => 'Cocina',
                'is_owner' => false,
                'permissions' => [
                    // The ticket board only. Nothing else.
                    'kitchen.view' => self::SI,
                    'kitchen.update' => self::SI,
                ],
            ],

            'courier' => [
                'name' => 'Repartidor',
                'is_owner' => false,
                'permissions' => [
                    'delivery.view_own' => self::SI,
                    'delivery.mark_delivered' => self::SI,
                ],
            ],
        ];
    }

    /**
     * @return array{name: string, is_owner: bool, permissions: array<string, bool>}
     */
    public static function get(string $code): array
    {
        return self::all()[$code] ?? throw new \InvalidArgumentException(
            "No existe el rol base «{$code}»."
        );
    }
}
