<?php

declare(strict_types=1);

namespace Platform\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Tenancy\Database\TenantDatabaseGuard;
use Platform\Tenancy\TenantContext;
use Platform\Tenancy\TenantResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo primero que pasa en cada petición: averiguar de qué negocio es.
 *
 * Va antepuesto a los grupos `web` y `api`, y **antes de la autenticación**.
 * El orden no es casual: así el usuario se busca DENTRO del negocio ya
 * resuelto, y el mismo correo en dos negocios entra al que corresponde al
 * subdominio, sin un campo extra en el formulario de login.
 *
 * Si el host no lleva negocio (el dominio raíz, o `admin.`) la petición sigue
 * sin contexto. Eso es correcto: ahí viven el registro de negocios nuevos y la
 * super administración, que es cross-tenant por definición.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
        private readonly TenantDatabaseGuard $guard,
        private readonly CurrentCapabilities $capabilities,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->resolver->slugFromHost($request->getHost());

        if ($slug === null) {
            return $next($request);
        }

        // Lanza TenantNotFound (404) si no existe. Nunca cae a un negocio por
        // defecto: un fallo de configuración que sirviera los datos de otro
        // sería mucho peor que una página de error.
        $tenant = $this->resolver->bySlug($slug);

        if (! $tenant->status->allowsAccess()) {
            return response()->json([
                'message' => 'Esta cuenta está cerrada. Escríbenos si crees que es un error.',
            ], Response::HTTP_FORBIDDEN);
        }

        $this->context->set($tenant);
        $this->guard->apply($tenant->id);

        // Los guards de autenticación memorizan al usuario, y las capacidades
        // memorizan el resultado de resolverlas. Las dos memorias valen POR
        // PETICIÓN: sin esto, en un proceso persistente (Octane, un worker) la
        // siguiente petición podría verse con el usuario y los permisos de la
        // anterior — y de otro negocio.
        Auth::forgetGuards();
        $this->capabilities->reset();

        return $next($request);
    }

    /**
     * Limpia la conexión antes de devolverla al pool.
     *
     * Junto con el listener de TransactionBeginning, es lo que impide que una
     * conexión reutilizada arrastre el negocio de la petición anterior.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($this->guard->current() !== null) {
            $this->guard->clear();
        }
    }
}
