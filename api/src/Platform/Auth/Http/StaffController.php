<?php

declare(strict_types=1);

namespace Platform\Auth\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Who may sign in at this screen: names to tap, not an email field.
 *
 * Never returns the PIN hash, who has one set, or email addresses — only what
 * it takes to paint a list of buttons.
 */
final class StaffController
{
    public function __invoke(): JsonResponse
    {
        $staff = User::query()
            ->with('roles')
            ->where('is_active', true)
            // No PIN means they cannot get in this way, so they are not shown: a button
            // that never works is worse than no button.
            ->whereNotNull('pin_hash')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'roleName' => $user->roles->first()?->name,
            ])
            ->values();

        return response()->json(['staff' => $staff]);
    }
}
