<?php

declare(strict_types=1);

namespace Modules\Orders;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * Los pedidos: lo que se vende, por dónde va y cómo se paga.
 *
 * De núcleo, como la carta. Un negocio de comida que no puede tomar un pedido
 * no tiene sistema, y la cocina, la caja y el portal cuelgan todos de aquí.
 */
final class OrdersModule extends ModuleManifest
{
    public function code(): string
    {
        return 'orders';
    }

    public function name(): string
    {
        return 'Pedidos';
    }

    public function description(): string
    {
        return 'Lo que te piden, por dónde va cada uno y cuánto han pagado.';
    }

    public function isCore(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return ['catalog'];
    }

    public function routes(): ?string
    {
        return __DIR__.'/Interfaces/Http/Routes/api.php';
    }

    public function migrations(): ?string
    {
        return __DIR__.'/Infrastructure/Migrations';
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return [
            'orders.view',
            'orders.create',
            'orders.confirm',
            'orders.advance',

            // Cancelar va aparte de confirmar, y con su par `_request`: es la
            // vía natural para sacar comida sin cobrarla, así que el mostrador
            // puede INICIARLO y el encargado lo autoriza con su PIN.
            'orders.cancel',
            'orders.cancel_request',

            'payments.confirm',
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            /*
             * Confirmar solo los pedidos que entran.
             *
             * Un local con cocina propia quiere mirar antes de aceptar. Uno
             * pequeño, donde el que atiende es el que cocina, prefiere que
             * caigan directo. El valor por defecto es el comportamiento de
             * hoy —confirmar a mano—, así que activarlo es una decisión, no
             * una sorpresa.
             */
            'auto_confirm' => Setting::bool(false),

            // A partir de cuántos minutos sin confirmar se marca en el tablero.
            // Un pedido olvidado veinte minutos es un cliente perdido.
            'unconfirmed_alert_minutes' => Setting::int(5)->min(1)->max(60),
        ];
    }
}
