<?php

declare(strict_types=1);

namespace Platform\Auth;

use App\Models\User;
use Platform\Audit\AuthorizedBy;
use Platform\Auth\Exceptions\AuthorizationRefused;

/**
 * Validates the PIN of whoever authorises an action.
 *
 * WHO authorises is required, not just the PIN: matching "any user here" would
 * turn 1-in-10,000 into 1-in-1,250 with eight staff. And nobody authorises
 * themselves, or "asking for authorization" would be a form you sign yourself.
 */
final class PinAuthorizer
{
    public function authorize(string $userId, string $pin, string $permission): AuthorizedBy
    {
        // The tenant filter is already in place: Eloquent's global scope and RLS.
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

        // They hold it, but only to request it: they cannot sign for themselves.
        return ! in_array($permission, $user->permissionsNeedingAuthorization(), true);
    }
}
