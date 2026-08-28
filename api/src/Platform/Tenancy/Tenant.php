<?php

declare(strict_types=1);

namespace Platform\Tenancy;

/**
 * El negocio en curso.
 *
 * Es un objeto de sólo lectura y **no un modelo de Eloquent**, a propósito. Se
 * resuelve una vez por petición, se cachea en Redis y se pasea por todo el
 * sistema: si fuera un modelo, cualquiera podría escribirle encima o lanzar
 * consultas desde él sin querer. Aquí no hay nada que tocar.
 *
 * Lleva sólo lo que hace falta para decidir: quién es, si puede entrar y qué
 * plan tiene. Todo lo demás —logo, dirección, configuración— se consulta
 * cuando se necesita.
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
     * Esta forma es la que se guarda en caché. Cambiarla obliga a invalidar.
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
