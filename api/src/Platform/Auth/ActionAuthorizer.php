<?php

declare(strict_types=1);

namespace Platform\Auth;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Platform\Audit\AuthorizedBy;
use Platform\Capabilities\CurrentCapabilities;

/**
 * Decides whether a sensitive action may run, and in whose name.
 *
 * The cashier starts it, a manager types four digits without signing anyone
 * out, and the action is recorded under whoever authorised it.
 */
final class ActionAuthorizer
{
    public function __construct(
        private readonly CurrentCapabilities $capabilities,
        private readonly PinAuthorizer $pins,
    ) {}

    /**
     * Null when the caller may act alone; otherwise the authorizer.
     *
     * @throws ValidationException when authorization is missing.
     */
    public function resolve(Request $request, string $permission): ?AuthorizedBy
    {
        if ($this->capabilities->get()->can($permission)) {
            return null;
        }

        $userId = $request->input('authorized_by');
        $pin = $request->input('authorization_pin');

        if (! is_string($userId) || ! is_string($pin) || $userId === '' || $pin === '') {
            // 422 with a field name rather than 403: it tells the till the PIN
            // dialog can fix this, instead of a dead end with a customer waiting.
            throw ValidationException::withMessages([
                'authorization_pin' => 'Esta acción necesita que la autorice un supervisor.',
            ]);
        }

        return $this->pins->authorize($userId, $pin, $permission);
    }
}
