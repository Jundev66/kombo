<?php

declare(strict_types=1);

namespace Platform\Capabilities;

use Illuminate\Contracts\Auth\Factory as Auth;
use Platform\Tenancy\TenantContext;

/**
 * Las capacidades de ESTA petición, calculadas una sola vez.
 *
 * Singleton explícito y memoizado por petición: entre el middleware de módulo,
 * el de permiso y el propio caso de uso, esto se pregunta tres o cuatro veces
 * en cada petición, y recalcularlo cada vez sería tres o cuatro viajes a Redis
 * para obtener lo mismo.
 */
final class CurrentCapabilities
{
    private ?TenantCapabilities $resolved = null;

    public function __construct(
        private readonly CapabilityResolver $resolver,
        private readonly TenantContext $context,
        private readonly Auth $auth,
    ) {}

    public function get(): TenantCapabilities
    {
        return $this->resolved ??= $this->compute();
    }

    /**
     * Descarta y recalcula. Hace falta cuando algo cambió DENTRO de la misma
     * petición: encender un módulo y responder ya con el menú nuevo, sin que
     * el dueño tenga que recargar para ver lo que acaba de activar.
     */
    public function refresh(): TenantCapabilities
    {
        $this->resolved = null;

        return $this->get();
    }

    /** Lo llama ResolveTenant: la memoria vale por petición, no más. */
    public function reset(): void
    {
        $this->resolved = null;
    }

    /**
     * @return list<string>
     */
    public function permissionsOfCurrentUser(): array
    {
        $user = $this->auth->guard()->user();

        // Sin sesión, cero permisos — no es un error: `/me` responde sin
        // sesión a propósito, porque la pantalla de login necesita el nombre y
        // el logo del negocio antes de que nadie entre.
        if ($user === null) {
            return [];
        }

        return method_exists($user, 'permissionNames') ? $user->permissionNames() : [];
    }

    private function compute(): TenantCapabilities
    {
        $tenant = $this->context->current();

        return $this->resolver->resolve(
            tenantId: $tenant->id,
            planCode: $tenant->planCode,
            userPermissions: $this->permissionsOfCurrentUser(),
        );
    }
}
