<?php

declare(strict_types=1);

namespace Platform\Capabilities\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Modules\ModuleRegistry;
use Platform\Tenancy\TenantContext;

/**
 * `GET /api/v1/me` — el eje del sistema.
 *
 * Devuelve el negocio, quién entró, y las capacidades **ya resueltas**: qué
 * módulos están encendidos, qué permisos tiene esta persona, cómo está
 * configurado cada módulo y cuáles son los techos del plan.
 *
 * El frontend pinta menú, rutas y botones a partir de esto y **no decide
 * nada**. No existe una lista de módulos escrita en React; el servidor valida
 * después contra exactamente la misma fuente que pintó la pantalla.
 *
 * **Responde también SIN sesión**, y eso es a propósito: la pantalla de login
 * necesita el nombre y el logo del negocio antes de que nadie entre. Un login
 * que dice «Kombo» en vez de «El Sazón» parece de otro producto.
 */
final class MeController
{
    public function __construct(
        private readonly CurrentCapabilities $capabilities,
        private readonly TenantContext $context,
        private readonly ModuleRegistry $registry,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->context->has()) {
            // Dominio raíz o `admin.`: no hay negocio, y eso no es un error.
            return response()->json([
                'tenant' => null,
                'user' => null,
            ]);
        }

        $tenant = $this->context->current();
        $user = $request->user();

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'logoUrl' => $tenant->logoUrl,
                'status' => $tenant->status->value,
                // El huso del NEGOCIO. El panel enseña fechas de pedidos, y la
                // regla de la casa es que un dato que depende del negocio se
                // resuelve con SU hora: un dueño que abre el panel de viaje
                // vería el pedido de anoche fechado hoy.
                'timezone' => $tenant->timezone,
                // Que el negocio sepa que está vencido antes de descubrirlo
                // porque algo dejó de funcionar.
                'needsAttention' => $tenant->status->needsAttention(),
                'canWrite' => $tenant->status->allowsWrites(),
            ],

            'user' => $user === null ? null : [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'isOwner' => $user->isOwner(),
                // El nombre del rol, no su código: va a una pantalla. Sirve
                // para que quien mira sepa con qué permisos está mirando.
                'roleName' => $user->roles->first()?->name,
                // La caja necesita saber CUÁLES acciones le van a pedir un PIN,
                // para abrir el diálogo antes de intentarlas en vez de después
                // de que el servidor la rechace con el cliente delante.
                'needsAuthorization' => $user->permissionsNeedingAuthorization(),
            ],

            // Las etiquetas del menú salen del manifiesto del backend, no de
            // una constante en React: así renombrar un módulo es una línea en
            // un sitio.
            'moduleNames' => $this->moduleNames(),

            'demo' => config('kombo.demo_tools') === true,

            ...$this->capabilities->get()->toArray(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function moduleNames(): array
    {
        $names = [];

        foreach ($this->registry->all() as $code => $manifest) {
            $names[$code] = $manifest->name();
        }

        return $names;
    }
}
