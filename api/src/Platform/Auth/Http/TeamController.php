<?php

declare(strict_types=1);

namespace Platform\Auth\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Audit\AuditLogger;
use Platform\Auth\RoleCatalog;
use Platform\Capabilities\CurrentCapabilities;
use Platform\Tenancy\TenantContext;
use Shared\Domain\Exceptions\UserError;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The tenant's team.
 *
 * Four rules that are not bureaucracy: the plan ceiling is enforced here, on
 * create; nobody is deleted, only deactivated, so old orders keep their names;
 * there is always one active owner left; and only an owner appoints — or edits
 * — another owner, because `update()` accepts `password`.
 */
final class TeamController
{
    public function __construct(
        private readonly CurrentCapabilities $capabilities,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        $users = User::with('roles')->orderBy('name')->get();

        $limit = $this->capabilities->get()->limits->maxUsers;

        return response()->json([
            'data' => $users->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'isActive' => (bool) $user->is_active,
                'isOwner' => $user->isOwner(),
                'roleCode' => $user->roles->first()?->code,
                'roleName' => $user->roles->first()?->name,
                // Without a PIN they cannot reach the till or the kitchen, and that has to
                // be visible at a glance when someone says "it won't let me in".
                'hasPin' => $user->pin_hash !== null,
                'lastLoginAt' => $user->last_login_at?->toAtomString(),
            ])->all(),

            'meta' => [
                'active' => $users->where('is_active', true)->count(),
                // `null` is UNLIMITED, never zero.
                'maxUsers' => $limit,
                'roles' => $this->availableRoles(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role_code' => ['required', 'string'],
            // Four digits, and optional: only needed to reach the till or the kitchen.
            'pin' => ['nullable', 'digits:4'],
        ]);

        $this->assertRoomInPlan();
        $this->assertEmailIsFree($data['email']);
        $this->assertCanAssignRole($data['role_code']);

        $role = $this->roleOrFail($data['role_code']);

        $user = DB::transaction(function () use ($data, $role): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                // No `Hash::make`: the model casts both as `hashed`, and hashing here would
                // store a hash of a hash — nobody could sign in and nothing would say why.
                'password' => $data['password'],
                'pin_hash' => $data['pin'] ?? null,
                'is_active' => true,
            ]);

            DB::table('role_user')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->context->id(),
                'user_id' => $user->getKey(),
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $user;
        });

        $this->audit->record(
            action: 'users.created',
            entityType: 'user',
            entityId: (string) $user->getKey(),
            after: ['email' => $data['email'], 'role' => $data['role_code']],
        );

        return response()->json(['data' => ['id' => $user->getKey()]], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'password' => ['sometimes', 'string', 'min:8', 'max:100'],
            'role_code' => ['sometimes', 'string'],
            // Empty string removes the PIN.
            'pin' => ['sometimes', 'nullable'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = User::with('roles')->find($id) ?? throw new NotFoundHttpException('Esa persona no está en tu equipo.');

        $this->assertCanTouchOwner($user);

        if (isset($data['role_code'])) {
            $this->assertCanAssignRole($data['role_code']);
        }

        if (array_key_exists('is_active', $data)) {
            $this->assertNotSelf($user, 'Nadie se desactiva a sí mismo.');

            if ($data['is_active'] === false) {
                $this->assertNotLastOwner($user);
            }

            // Reactivating someone also takes a seat in the plan.
            if ($data['is_active'] === true && ! $user->is_active) {
                $this->assertRoomInPlan();
            }
        }

        $changes = [];

        foreach (['name', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }

        if (isset($data['password'])) {
            // No `Hash::make`: the model casts it.
            $changes['password'] = $data['password'];
        }

        if (array_key_exists('pin', $data)) {
            $pin = $data['pin'];

            if ($pin !== null && $pin !== '' && ! preg_match('/^\d{4}$/', (string) $pin)) {
                throw new class('El PIN son cuatro dígitos.') extends UserError
                {
                    public function field(): ?string
                    {
                        return 'pin';
                    }
                };
            }

            $changes['pin_hash'] = $pin === null || $pin === '' ? null : (string) $pin;
        }

        DB::transaction(function () use ($user, $changes, $data): void {
            if ($changes !== []) {
                $user->update($changes);
            }

            if (isset($data['role_code'])) {
                $this->assertNotLastOwnerIfLosingIt($user, $data['role_code']);

                $role = $this->roleOrFail($data['role_code']);

                // One role per person: overlapping roles make "why can't I?" unanswerable.
                DB::table('role_user')->where('user_id', $user->getKey())->delete();

                DB::table('role_user')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $this->context->id(),
                    'user_id' => $user->getKey(),
                    'role_id' => $role->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->audit->record(
            action: 'users.updated',
            entityType: 'user',
            entityId: (string) $user->getKey(),
            after: array_diff_key($data, ['password' => null, 'pin' => null]),
        );

        return response()->json(['data' => ['id' => $user->getKey()]]);
    }

    /** Deactivates rather than deletes. */
    public function destroy(string $id): JsonResponse
    {
        $user = User::with('roles')->find($id) ?? throw new NotFoundHttpException('Esa persona no está en tu equipo.');

        $this->assertCanTouchOwner($user);
        $this->assertNotSelf($user, 'No puedes darte de baja a ti mismo.');
        $this->assertNotLastOwner($user);

        $user->update(['is_active' => false]);

        $this->audit->record(
            action: 'users.deactivated',
            entityType: 'user',
            entityId: (string) $user->getKey(),
            after: ['email' => $user->email],
        );

        return response()->json(status: 204);
    }

    /**
     * The roles this tenant may hand out, taken from the base catalog so a new
     * one appears on its own.
     *
     * @return list<array<string, string>>
     */
    private function availableRoles(): array
    {
        $existingOnes = DB::table('roles')->pluck('code')->all();
        $canAppointOwners = $this->actorIsOwner();

        return collect(RoleCatalog::all())
            ->filter(fn (array $_, string $code): bool => in_array($code, $existingOnes, true))
            // A manager is not offered "Owner": showing an option the server will
            // reject is worse than hiding it — you find out after filling the form.
            ->filter(fn (array $role): bool => $canAppointOwners || ! $role['is_owner'])
            ->map(fn (array $role, string $code): array => ['code' => $code, 'name' => $role['name']])
            ->values()
            ->all();
    }

    /** Is the caller an owner? */
    private function actorIsOwner(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isOwner();
    }

    /** Only an owner appoints another owner. */
    private function assertCanAssignRole(string $code): void
    {
        $catalog = RoleCatalog::all()[$code] ?? null;

        if ($catalog === null || $catalog['is_owner'] === false || $this->actorIsOwner()) {
            return;
        }

        throw new class('Sólo el dueño puede nombrar a otro dueño.') extends UserError
        {
            public function field(): ?string
            {
                return 'role_code';
            }
        };
    }

    /**
     * An owner's account is only touched by an owner.
     *
     * The other half of the rule above, and without it the first is decorative:
     * `update()` accepts `password`.
     */
    private function assertCanTouchOwner(User $user): void
    {
        if (! $user->isOwner() || $this->actorIsOwner()) {
            return;
        }

        throw new AccessDeniedHttpException(
            'La cuenta del dueño sólo la puede cambiar el dueño.'
        );
    }

    private function roleOrFail(string $code): object
    {
        return DB::table('roles')->where('code', $code)->first()
            ?? throw new class('Ese rol no existe en tu negocio.') extends UserError
            {
                public function field(): ?string
                {
                    return 'role_code';
                }
            };
    }

    /** The plan ceiling. Only ACTIVE users count towards it. */
    private function assertRoomInPlan(): void
    {
        $limit = $this->capabilities->get()->limits->maxUsers;

        if ($limit === null) {
            return;
        }

        if (User::where('is_active', true)->count() >= $limit) {
            throw new class('Tu plan llega hasta aquí. Para sumar a alguien más, hay que subir de plan.') extends UserError
            {
                public function field(): ?string
                {
                    return 'name';
                }
            };
        }
    }

    private function assertEmailIsFree(string $email): void
    {
        // Within THIS tenant. The same email in two tenants is normal: one person
        // can run two shops.
        if (User::where('email', $email)->exists()) {
            throw new class('Ya hay alguien con ese correo en tu equipo.') extends UserError
            {
                public function field(): ?string
                {
                    return 'email';
                }
            };
        }
    }

    private function assertNotSelf(User $user, string $message): void
    {
        if ((string) $user->getKey() === (string) auth()->id()) {
            throw new class($message) extends UserError {};
        }
    }

    /**
     * There is always one owner left.
     *
     * A tenant with no active owner cannot be configured, and there is no way
     * to fix it from the inside.
     */
    private function assertNotLastOwner(User $user): void
    {
        if (! $user->isOwner()) {
            return;
        }

        $others = User::where('is_active', true)
            ->where('id', '!=', $user->getKey())
            ->get()
            ->filter(fn (User $other): bool => $other->isOwner())
            ->count();

        if ($others === 0) {
            throw new class('Tiene que quedar al menos un dueño activo.') extends UserError {};
        }
    }

    private function assertNotLastOwnerIfLosingIt(User $user, string $newRole): void
    {
        $catalog = RoleCatalog::all()[$newRole] ?? null;

        if ($catalog !== null && $catalog['is_owner'] === false) {
            $this->assertNotLastOwner($user);
        }
    }
}
