<?php

declare(strict_types=1);

namespace Platform\Capabilities;

/**
 * The plan ceilings.
 *
 * `null` means UNLIMITED, never zero. Zero would mean "none", a different
 * answer that would lock an owner out of their own tenant.
 */
final readonly class PlanLimits
{
    public function __construct(
        public ?int $maxUsers = null,
        public ?int $maxProducts = null,
        public ?int $maxOrdersMonth = null,
    ) {}

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function fromRow(object|array $row): self
    {
        $data = (array) $row;

        return new self(
            maxUsers: isset($data['max_users']) ? (int) $data['max_users'] : null,
            maxProducts: isset($data['max_products']) ? (int) $data['max_products'] : null,
            maxOrdersMonth: isset($data['max_orders_month']) ? (int) $data['max_orders_month'] : null,
        );
    }

    /**
     * Would one more go over the ceiling?
     *
     * Asked with the CURRENT count, before creating. `>=` and not `>` because
     * at 8 of 8 users the ninth no longer fits.
     */
    public function exceeds(?int $limit, int $current): bool
    {
        return $limit !== null && $current >= $limit;
    }

    /**
     * @return array<string, int|null>
     */
    public function toArray(): array
    {
        return [
            'maxUsers' => $this->maxUsers,
            'maxProducts' => $this->maxProducts,
            'maxOrdersMonth' => $this->maxOrdersMonth,
        ];
    }
}
