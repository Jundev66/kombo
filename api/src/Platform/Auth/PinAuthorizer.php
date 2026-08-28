<?php

declare(strict_types=1);

namespace Platform\Auth;

use App\Models\User;
use Platform\Audit\AuthorizedBy;
use Platform\Auth\Exceptions\AuthorizationRefused;

/**
 * Valida el PIN de quien autoriza una acción.
 *
 * Dos decisiones que parecen detalles y no lo son:
 *
 * **Se pide QUIÉN autoriza, no sólo el PIN.** Buscar «algún usuario de este
 * negocio cuyo PIN coincida» multiplica por N la superficie de adivinación: con
 * ocho empleados, acertar un PIN de cuatro dígitos pasa de 1 entre 10.000 a 1
 * entre 1.250. Exigir el nombre lo devuelve a 1 entre 10.000.
 *
 * **Nadie puede autorizarse a sí mismo.** Si el permiso que se está pidiendo
 * está en la lista de los que ese usuario sólo puede SOLICITAR, su PIN no
 * sirve. Sin esta comprobación, «pedir autorización» sería un trámite que el
 * propio cajero se firma.
 */
final class PinAuthorizer
{
    public function authorize(string $userId, string $pin, string $permission): AuthorizedBy
    {
        // El filtro por negocio ya está puesto: por el ámbito global de
        // Eloquent y, sobre todo, por RLS.
        $user = User::with('roles.permissions')->find($userId);

        if ($user === null || ! $user->is_active || ! $user->authorizesWithPin($pin)) {
            throw new AuthorizationRefused;
        }

        if (! $this->canExecute($user, $permission)) {
            throw new AuthorizationRefused;
        }

        return new AuthorizedBy(
            userId: (string) $user->getKey(),
            userName: (string) $user->name,
        );
    }

    private function canExecute(User $user, string $permission): bool
    {
        $granted = $user->permissionNames();

        if (in_array('*', $granted, true)) {
            return true;
        }

        if (! in_array($permission, $granted, true)) {
            return false;
        }

        // Lo tiene, pero sólo para solicitarlo: no puede firmarse a sí mismo.
        return ! in_array($permission, $user->permissionsNeedingAuthorization(), true);
    }
}
