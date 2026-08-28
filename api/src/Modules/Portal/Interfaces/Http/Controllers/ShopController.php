<?php

declare(strict_types=1);

namespace Modules\Portal\Interfaces\Http\Controllers;

use App\Models\Delivery\DeliveryZoneModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Portal\Domain\ValueObjects\DaySchedule;
use Modules\Portal\Domain\ValueObjects\OpeningHours;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Tenancy\TenantContext;

/**
 * La tienda: todo lo que el portal necesita saber antes de pintar la carta.
 *
 * Una sola llamada, a propósito. En un teléfono con mala señal, cinco
 * peticiones para dibujar la primera pantalla son cinco oportunidades de que
 * el cliente vea algo a medias y se vaya.
 *
 * **Responde sin sesión.** Es la única parte del sistema pensada para alguien
 * que no tiene cuenta y no la va a tener.
 */
final class ShopController
{
    private const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CurrentCapabilities $capabilities,
    ) {}

    public function __invoke(): JsonResponse
    {
        $tenant = $this->context->current();
        $caps = $this->capabilities->get();

        $row = DB::table('tenants')->where('id', $tenant->id)->first();

        $hours = $this->hours();
        $localNow = Carbon::now($tenant->timezone)->toDateTimeImmutable();

        // El reparto necesita las dos cosas: que el negocio lo ofrezca y que
        // el módulo esté encendido. Ofrecerlo sin zonas sería prometer algo
        // que no se puede cumplir.
        $delivers = $caps->hasModule('delivery') && $caps->setting('portal.accepts_delivery', true) === true;

        $zones = $delivers
            ? DeliveryZoneModel::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            : collect();

        $pagoMovilDetails = trim((string) $caps->setting('portal.pago_movil_details', ''));

        return response()->json([
            'data' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'logoUrl' => $tenant->logoUrl,
                'brandColor' => $row?->brand_color,
                'phone' => $row?->phone,
                'address' => $row?->address,

                // Un aviso corto arriba del todo: «hoy no hay pollo».
                'notice' => trim((string) $caps->setting('portal.notice', '')) ?: null,

                'isOpen' => $hours['open']->isOpenAt($localNow),
                'hours' => $hours['days'],
                'timezone' => $tenant->timezone,

                'serviceTypes' => array_values(array_filter([
                    $caps->setting('portal.accepts_takeaway', true) === true ? 'takeaway' : null,
                    $delivers ? 'delivery' : null,
                ])),

                'zones' => $zones->map(fn (DeliveryZoneModel $zone): array => [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'feeCents' => $zone->fee_cents,
                    'estimatedMinutes' => $zone->estimated_minutes ?? $caps->setting('delivery.default_minutes', 45),
                ])->all(),

                'minimumOrderCents' => $delivers ? (int) $caps->setting('delivery.minimum_order_cents', 0) : 0,

                'paymentMethods' => array_values(array_filter([
                    $caps->setting('portal.accepts_cash', true) === true ? 'cash' : null,
                    // Sin datos a dónde mandar el dinero, no se ofrece: un
                    // botón de pagar que no dice a quién pagarle es una llamada
                    // de teléfono garantizada.
                    $caps->setting('portal.accepts_pago_movil', true) === true && $pagoMovilDetails !== ''
                        ? 'pago_movil'
                        : null,
                ])),

                'pagoMovilDetails' => $pagoMovilDetails ?: null,
                'paymentWindowMinutes' => (int) $caps->setting('portal.payment_window_minutes', 120),

                // La tasa del día, para enseñar los precios en bolívares. Es
                // presentación: lo que se guarda son centavos de dólar.
                'exchangeRate' => $this->rate(),
            ],
        ]);
    }

    /**
     * @return array{open: OpeningHours, days: list<array<string, mixed>>}
     */
    private function hours(): array
    {
        $rows = DB::table('business_hours')->orderBy('weekday')->get()->keyBy('weekday');

        $schedules = [];
        $days = [];

        for ($weekday = 0; $weekday <= 6; $weekday++) {
            $row = $rows->get($weekday);

            // Sin fila configurada, CERRADO. El fallo seguro es no aceptar: un
            // pedido de un día que nadie configuró llega a una cocina apagada.
            $schedules[$weekday] = $row === null || (bool) $row->is_closed
                ? DaySchedule::closed()
                : DaySchedule::open($row->opens_at, $row->closes_at);

            $days[] = [
                'weekday' => $weekday,
                'label' => self::DIAS[$weekday],
                'opensAt' => $schedules[$weekday]->isClosed ? null : substr((string) $row?->opens_at, 0, 5),
                'closesAt' => $schedules[$weekday]->isClosed ? null : substr((string) $row?->closes_at, 0, 5),
                'isClosed' => $schedules[$weekday]->isClosed,
            ];
        }

        return ['open' => OpeningHours::of($schedules), 'days' => $days];
    }

    private function rate(): ?float
    {
        $rate = DB::table('exchange_rates')->orderByDesc('effective_date')->value('rate');

        return $rate === null ? null : (float) $rate;
    }
}
