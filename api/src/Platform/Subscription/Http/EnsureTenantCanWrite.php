<?php

declare(strict_types=1);

namespace Platform\Subscription\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Platform\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un negocio suspendido **entra, lee y exporta. No escribe.**
 *
 * Las dos mitades de esa frase importan. Borrarle el acceso a sus propios datos
 * a alguien que confió en el sistema no es una palanca de cobro aceptable: sus
 * pedidos, sus clientes y su carta siguen siendo suyos, y tiene que poder
 * sacarlos aunque nos deba tres meses. Lo que se corta es seguir operando
 * gratis.
 *
 * **Un solo middleware, no un `if` en cada controlador.** Es exactamente donde
 * falló el proyecto anterior: la comprobación estaba puesta en 2 de unos 20
 * controladores, así que un negocio suspendido seguía trabajando con
 * normalidad por las otras 18 puertas. Aquí va en el grupo `api` entero, y
 * añadir un módulo nuevo no puede olvidarse de ella porque nadie la escribe.
 */
final class EnsureTenantCanWrite
{
    /** Lo que no cambia nada, y por tanto nunca se bloquea. */
    private const LECTURA = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Lo que se deja pasar aunque esté suspendido.
     *
     * Salir tiene que funcionar siempre —dejar a alguien encerrado en una
     * sesión que no puede cerrar es de mal gusto— y entrar también: si no
     * pudiera entrar, no podría leer ni exportar nada, que es justo lo que sí
     * se le permite.
     */
    private const SIEMPRE = ['auth.login', 'auth.logout', 'auth.device', 'auth.pin'];

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::LECTURA, true)) {
            return $next($request);
        }

        if (! $this->context->has()) {
            // Sin negocio no hay suscripción que comprobar: es la super
            // administración, o un webhook que resuelve el suyo por dentro.
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($routeName !== null && in_array($routeName, self::SIEMPRE, true)) {
            return $next($request);
        }

        $tenant = $this->context->current();

        if ($tenant->status->allowsWrites()) {
            return $next($request);
        }

        /*
         * 402 y no 403.
         *
         * 403 dice «no tienes permiso», que es mentira y manda al dueño a
         * revisar los roles de su equipo. 402 dice lo que pasa de verdad: hay
         * algo que pagar. Y el mensaje lleva el motivo, porque un error sin
         * explicación en la pantalla de alguien que está trabajando es una
         * llamada al soporte.
         */
        return new JsonResponse([
            'message' => $tenant->status->value === 'suspended'
                ? 'La cuenta está suspendida por falta de pago. Puedes consultar y exportar tus datos; para volver a operar, ponte al día.'
                : 'La cuenta está cerrada.',
            'tenantStatus' => $tenant->status->value,
        ], 402);
    }
}
