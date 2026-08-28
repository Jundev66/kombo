<?php

declare(strict_types=1);

namespace Platform\Auth;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Platform\Audit\AuthorizedBy;
use Platform\Capabilities\CurrentCapabilities;

/**
 * Resuelve si una acción sensible se puede ejecutar, y a nombre de quién.
 *
 * El problema real: hay cosas que alguien de mostrador necesita hacer con un
 * cliente delante y el encargado al lado —anular un pedido cobrado, devolver
 * dinero, cambiar un precio—. Frenarlas hasta que uno cierre sesión y abra la
 * suya es inviable en la práctica, y lleva a que todos acaben usando la clave
 * del encargado. Eso es peor que no tener permisos: se pierde por completo el
 * rastro de quién hizo qué.
 *
 * La solución es el PIN: el cajero inicia, el encargado escribe cuatro dígitos
 * sin cerrar nada, y la acción **queda registrada a nombre de quien autorizó**.
 */
final class ActionAuthorizer
{
    public function __construct(
        private readonly CurrentCapabilities $capabilities,
        private readonly PinAuthorizer $pins,
    ) {}

    /**
     * Devuelve null si quien pide puede hacerlo solo —entonces la acción va a
     * su nombre— o el autorizador si hizo falta un PIN.
     *
     * @throws ValidationException cuando falta la autorización.
     */
    public function resolve(Request $request, string $permission): ?AuthorizedBy
    {
        if ($this->capabilities->get()->can($permission)) {
            return null;
        }

        $userId = $request->input('authorized_by');
        $pin = $request->input('authorization_pin');

        if (! is_string($userId) || ! is_string($pin) || $userId === '' || $pin === '') {
            // **422 con nombre de campo, no 403.**
            //
            // La diferencia importa en la pantalla: un 403 le dice a la caja
            // «no puedes» y ahí se acaba. Un error de validación sobre
            // `authorization_pin` le dice «esto tiene solución aquí mismo», y
            // la caja sabe abrir el diálogo del PIN en vez de dejar al cajero
            // mirando un mensaje sin salida con un cliente esperando.
            throw ValidationException::withMessages([
                'authorization_pin' => 'Esta acción necesita que la autorice un supervisor.',
            ]);
        }

        return $this->pins->authorize($userId, $pin, $permission);
    }
}
