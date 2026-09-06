<?php

declare(strict_types=1);

namespace Platform\Capabilities\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Modules\ModuleRegistry;
use Platform\Tenancy\TenantContext;

/**
 * `GET /api/v1/me` — the hub of the system.
 *
 * Returns the tenant, who signed in, and the already-resolved capabilities. The
 * frontend paints menu, routes and buttons from this and decides nothing; the
 * server later validates against the very same source.
 *
 * It answers WITHOUT a session too: the login screen needs the tenant's name
 * and logo before anyone signs in.
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
            // Root domain or `admin.`: there is no tenant, and that is not an error.
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
                // The TENANT's timezone. An owner opening the dashboard while travelling
                // would otherwise see last night's order dated today.
                'timezone' => $tenant->timezone,
                // So the tenant knows it is overdue before discovering it because
                // something stopped working.
                'needsAttention' => $tenant->status->needsAttention(),
                'canWrite' => $tenant->status->allowsWrites(),
            ],

            'user' => $user === null ? null : [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'isOwner' => $user->isOwner(),
                // The role's name, not its code: it goes on a screen, so whoever is
                // looking knows which permissions they are looking with.
                'roleName' => $user->roles->first()?->name,
                // The till needs to know WHICH actions will ask for a PIN, so it can open
                // the dialog beforehand rather than after a rejection.
                'needsAuthorization' => $user->permissionsNeedingAuthorization(),
            ],

            // Menu labels come from the backend manifest, not a constant in React:
            // renaming a module is one line in one place.
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
