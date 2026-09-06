<?php

declare(strict_types=1);

namespace Platform\Tenancy;

/**
 * The current tenant.
 *
 * A read-only object and deliberately NOT an Eloquent model: it is resolved
 * once per request, cached in Redis and passed all over the system. As a model,
 * anyone could write to it or fire queries from it by accident.
 *
 * It carries only what it takes to decide: who it is, whether it may come in,
 * and which plan it has.
 */
final readonly class Tenant
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $planCode,
        public TenantStatus $status,
        public ?string $logoUrl = null,
        public string $timezone = 'America/Caracas',
    ) {}

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function fromRow(object|array $row): self
    {
        $data = (array) $row;

        return new self(
            id: (string) $data['id'],
            slug: (string) $data['slug'],
            name: (string) $data['name'],
            planCode: (string) $data['plan_code'],
            status: TenantStatus::from((string) $data['status']),
            logoUrl: isset($data['logo_url']) ? (string) $data['logo_url'] : null,
            timezone: (string) ($data['timezone'] ?? 'America/Caracas'),
        );
    }

    /**
     * This shape is what gets cached. Changing it forces an invalidation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'plan_code' => $this->planCode,
            'status' => $this->status->value,
            'logo_url' => $this->logoUrl,
            'timezone' => $this->timezone,
        ];
    }
}
