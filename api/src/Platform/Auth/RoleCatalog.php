<?php

declare(strict_types=1);

namespace Platform\Auth;

/**
 * Los roles base que recibe un negocio al darse de alta.
 *
 * Declaran permisos de módulos que quizá ese negocio no tenga encendidos. No
 * pasa nada: al aplicarlos se filtran contra los módulos activos, porque un
 * permiso de un módulo apagado no existe en el sistema. Así el catálogo se
 * escribe una vez y sirve tanto al puesto que sólo vende por el portal como al
 * local con caja, cocina y delivery.
 *
 * Lo que SÍ importa es que el permiso exista en algún manifiesto. Repartir uno
 * de un módulo que no se ha construido no rompe nada —se filtra igual— y es
 * exactamente el problema: el catálogo dice que el encargado puede algo, no
 * puede, y nadie se entera hasta que lo intenta.
 */
final class RoleCatalog
{
    /** Puede ejecutarlo solo. */
    private const SI = false;

    /** Puede INICIARLO; ejecutarlo exige el PIN de alguien que sí puede. */
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
                // Vacío a propósito: el dueño no lleva filas de permisos. Se
                // resuelve como `['*']` y se expande contra los módulos que el
                // negocio tenga encendidos HOY, así que al encender uno nuevo
                // ya puede usarlo sin que nadie le añada nada.
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
                    'customers.view' => self::SI,
                    'customers.manage' => self::SI,
                    'reports.view_sales' => self::SI,
                    // NO lleva `users.manage`: quien puede crear usuarios puede
                    // crearse una cuenta de dueño. El equipo lo maneja el dueño.
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

                    // Éstas son las vías naturales para sacar dinero de la
                    // caja, así que se inician pero no se ejecutan solas.
                    'counter.void_request' => self::SOLICITA,
                    'counter.discount_request' => self::SOLICITA,
                ],
            ],

            'kitchen' => [
                'name' => 'Cocina',
                'is_owner' => false,
                'permissions' => [
                    // Sólo la pantalla de comandas. Nada más.
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
