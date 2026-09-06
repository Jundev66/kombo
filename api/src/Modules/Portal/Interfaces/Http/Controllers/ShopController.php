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
 * The shop: everything the portal needs before painting the menu, in a single
 * call — on a phone with poor signal, five requests are five chances to see a
 * half-drawn screen and leave.
 *
 * It answers without a session: the only part of the system built for somebody
 * who has no account and is not going to have one.
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

        // Delivery needs both: that the tenant offers it and that the module is on.
        // Offering it with no zones would promise something undeliverable.
        $delivers = $caps->hasModule('delivery') && $caps->setting('portal.accepts_delivery', true) === true;

        $zones = $delivers
            ? DeliveryZoneModel::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            : collect();

        $mobilePaymentDetails = trim((string) $caps->setting('portal.pago_movil_details', ''));

        return response()->json([
            'data' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'logoUrl' => $tenant->logoUrl,
                'brandColor' => $row?->brand_color,
                'phone' => $row?->phone,
                'address' => $row?->address,

                // A short notice at the very top: "no chicken today".
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
                    // With no details of where to send the money it is not offered: a pay
                    // button that does not say who to pay is a guaranteed phone call.
                    $caps->setting('portal.accepts_pago_movil', true) === true && $mobilePaymentDetails !== ''
                        ? 'pago_movil'
                        : null,
                ])),

                'mobilePaymentDetails' => $mobilePaymentDetails ?: null,
                'paymentWindowMinutes' => (int) $caps->setting('portal.payment_window_minutes', 120),

                // The rate of the day, to show prices in bolívares. Presentation only:
                // what is stored is dollar cents.
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

            // With no row configured, CLOSED. The safe failure: an order on an
            // unconfigured day reaches an unlit kitchen.
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
