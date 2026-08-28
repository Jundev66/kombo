<?php

declare(strict_types=1);

namespace Modules\Portal;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * El portal de pedidos: la cara pública del negocio.
 *
 * Es lo único del sistema que se usa **sin sesión**. El cliente entra por el
 * subdominio del negocio, mira la carta, pide y sigue su pedido con un enlace
 * — sin cuenta, sin contraseña y sin descargar nada.
 *
 * Se apaga entero, y hay negocios que lo tendrán apagado: un puesto de la
 * calle que sólo vende en el mostrador. Para ellos la dirección no sirve nada.
 */
final class PortalModule extends ModuleManifest
{
    public function code(): string
    {
        return 'portal';
    }

    public function name(): string
    {
        return 'Portal de pedidos';
    }

    public function description(): string
    {
        return 'La carta en línea, para que el cliente pida desde su teléfono.';
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return ['catalog', 'orders'];
    }

    public function routes(): ?string
    {
        return __DIR__.'/Interfaces/Http/Routes/api.php';
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            // Cómo se puede recibir el pedido. El reparto además exige el
            // módulo de reparto encendido: sin zonas no hay a dónde llevarlo.
            'accepts_takeaway' => Setting::bool(true),
            'accepts_delivery' => Setting::bool(true),

            /*
             * Cómo se paga.
             *
             * Efectivo contra entrega es lo que más se usa y no necesita nada
             * más. El pago móvil sí: hay que decirle al cliente A DÓNDE manda
             * el dinero, y por eso el portal no lo ofrece si esos datos están
             * vacíos — un botón de pagar que no dice a quién pagarle es una
             * llamada de teléfono garantizada.
             */
            'accepts_cash' => Setting::bool(true),
            'accepts_pago_movil' => Setting::bool(true),
            'pago_movil_details' => Setting::text('')->maxLength(300),

            /*
             * Cuánto se le espera al que se fue a pagar.
             *
             * Dos horas: lo que tarda ir al banco, no lo que tarda
             * arrepentirse. Pasado ese rato el pedido se cancela solo, para que
             * el tablero del negocio no se llene de pedidos que no existen.
             */
            'payment_window_minutes' => Setting::int(120)->min(10)->max(1440),

            // Un mensaje corto arriba del todo: «hoy no hay pollo», «cerramos
            // el 24». Vacío no se muestra.
            'notice' => Setting::text('')->maxLength(200),
        ];
    }
}
