<?php

declare(strict_types=1);

namespace Modules\Portal\Application\UseCases;

use App\Models\Delivery\DeliveryZoneModel;
use App\Models\Orders\OrderModel;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Application\UseCases\PlaceOrder;
use Modules\Orders\Domain\ValueObjects\ServiceType;
use Modules\Portal\Domain\Exceptions\PortalRefused;
use Modules\Portal\Domain\ValueObjects\DaySchedule;
use Modules\Portal\Domain\ValueObjects\OpeningHours;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Tenancy\TenantContext;
use Shared\Domain\ValueObjects\Money;

/**
 * Un pedido hecho desde el portal, por alguien que no tiene cuenta.
 *
 * Es la puerta más expuesta del sistema: cualquiera en internet puede llamarla.
 * De ahí las tres reglas que la gobiernan:
 *
 * **Del cliente sólo se acepta QUÉ y CUÁNTO.** Los precios salen del catálogo
 * y la tarifa de reparto de la zona. Ningún importe que llegue en la petición
 * se usa para nada.
 *
 * **Se comprueba que el negocio pueda cumplirlo**: que esté abierto, que
 * ofrezca ese modo de entrega, que reparta a esa zona y que acepte ese pago.
 * Aceptar un pedido que nadie va a preparar es peor que rechazarlo.
 *
 * **Efectivo y pago móvil no son lo mismo.** El de efectivo entra directo a la
 * cola del negocio; el de pago móvil nace esperando el comprobante, con fecha
 * de caducidad, porque el cliente se acaba de ir a la aplicación del banco y
 * puede no volver.
 */
final class PlacePortalOrder
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PlaceOrder $placeOrder,
        private readonly CurrentCapabilities $capabilities,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<array{product_id: string, quantity: int, modifier_ids?: list<string>, notes?: string|null}>  $items
     */
    public function execute(
        array $items,
        ServiceType $serviceType,
        string $paymentMethod,
        string $customerName,
        string $customerPhone,
        ?string $deliveryZoneId = null,
        ?string $deliveryAddress = null,
        ?string $notes = null,
    ): OrderModel {
        $caps = $this->capabilities->get();

        $this->assertOpen();
        $this->assertServiceOffered($serviceType);
        $this->assertPaymentAccepted($paymentMethod);

        $zone = null;

        if ($serviceType === ServiceType::Delivery) {
            if ($deliveryAddress === null || trim($deliveryAddress) === '') {
                throw PortalRefused::addressMissing();
            }

            $zone = DeliveryZoneModel::where('is_active', true)->find($deliveryZoneId)
                ?? throw PortalRefused::unknownZone();
        }

        // El pago móvil deja el pedido esperando el comprobante. El efectivo,
        // no: se paga al recibirlo.
        $awaitingPayment = $paymentMethod === 'pago_movil';

        $window = (int) $caps->setting('portal.payment_window_minutes', 120);
        $minimum = $serviceType === ServiceType::Delivery
            ? (int) $caps->setting('delivery.minimum_order_cents', 0)
            : 0;

        return $this->db->transaction(function () use (
            $items, $serviceType, $customerName, $customerPhone, $deliveryAddress,
            $zone, $notes, $awaitingPayment, $window, $minimum
        ): OrderModel {
            $order = $this->placeOrder->execute(
                items: $items,
                serviceType: $serviceType,
                channel: 'portal',
                customerName: $customerName,
                customerPhone: $customerPhone,
                deliveryAddress: $deliveryAddress,
                deliveryFeeCents: $zone?->fee_cents ?? 0,
                notes: $notes,
                awaitingPayment: $awaitingPayment,
                deliveryZoneId: $zone?->id,
                deliveryZoneName: $zone?->name,
                expiresAt: $awaitingPayment
                    ? new DateTimeImmutable("+{$window} minutes")
                    : null,
            );

            /*
             * El mínimo se comprueba DESPUÉS de armar el pedido, dentro de la
             * transacción que se deshace.
             *
             * Podría calcularse antes preguntando los precios al catálogo, y
             * sería tener el cálculo del total escrito en dos sitios. Aquí se
             * usa el que ya cuadró, y el número que se le enseña al cliente es
             * exactamente el que se habría cobrado. El correlativo tampoco
             * queda con huecos: el número se toma bajo cerrojo y se libera al
             * deshacer.
             */
            if ($minimum > 0 && $order->subtotal_cents < $minimum) {
                throw PortalRefused::belowMinimum('$'.Money::fromCents($minimum)->format());
            }

            return $order;
        });
    }

    private function assertOpen(): void
    {
        $rows = DB::table('business_hours')->orderBy('weekday')->get()->keyBy('weekday');

        $schedules = [];

        for ($weekday = 0; $weekday <= 6; $weekday++) {
            $row = $rows->get($weekday);

            $schedules[$weekday] = $row === null || (bool) $row->is_closed
                ? DaySchedule::closed()
                : DaySchedule::open($row->opens_at, $row->closes_at);
        }

        $localNow = Carbon::now($this->context->current()->timezone)->toDateTimeImmutable();

        if (! OpeningHours::of($schedules)->isOpenAt($localNow)) {
            throw PortalRefused::closed();
        }
    }

    private function assertServiceOffered(ServiceType $serviceType): void
    {
        $caps = $this->capabilities->get();

        $offered = match ($serviceType) {
            ServiceType::Delivery => $caps->hasModule('delivery')
                && $caps->setting('portal.accepts_delivery', true) === true,
            ServiceType::Takeaway => $caps->setting('portal.accepts_takeaway', true) === true,
            // Comer en el local no se pide por internet: se pide sentado.
            ServiceType::DineIn => false,
        };

        if (! $offered) {
            throw PortalRefused::serviceNotOffered($serviceType->value);
        }
    }

    private function assertPaymentAccepted(string $method): void
    {
        $caps = $this->capabilities->get();

        $accepted = match ($method) {
            'cash' => $caps->setting('portal.accepts_cash', true) === true,
            'pago_movil' => $caps->setting('portal.accepts_pago_movil', true) === true
                && trim((string) $caps->setting('portal.pago_movil_details', '')) !== '',
            default => false,
        };

        if (! $accepted) {
            throw PortalRefused::paymentNotAccepted();
        }
    }
}
