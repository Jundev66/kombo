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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * El equipo del negocio.
 *
 * Cuatro reglas que no son burocracia, son cosas que pasan:
 *
 * **El techo del plan se valida AQUÍ**, al crear. Es el único sitio donde
 * significa algo: un límite que sólo se enseña en una pantalla de
 * administración no es un límite, es una decoración.
 *
 * **No se borra a nadie: se desactiva.** Un usuario borrado se lleva por
 * delante quién confirmó aquel pedido y quién autorizó aquella anulación. Deja
 * de entrar, y lo que hizo sigue diciendo su nombre.
 *
 * **Siempre queda un dueño.** Quitarle el rol al último dejaría un negocio que
 * nadie puede configurar, y desde dentro no hay forma de arreglarlo.
 *
 * **Nadie se desactiva a sí mismo.** Es el clic que deja a alguien fuera de su
 * propio negocio un viernes por la tarde.
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

        $limite = $this->capabilities->get()->limits->maxUsers;

        return response()->json([
            'data' => $users->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'isActive' => (bool) $user->is_active,
                'isOwner' => $user->isOwner(),
                'roleCode' => $user->roles->first()?->code,
                'roleName' => $user->roles->first()?->name,
                // Sin PIN no puede entrar a la caja ni a la cocina, y eso hay
                // que verlo de un vistazo cuando alguien dice «no me deja».
                'hasPin' => $user->pin_hash !== null,
                'lastLoginAt' => $user->last_login_at?->toAtomString(),
            ])->all(),

            'meta' => [
                'active' => $users->where('is_active', true)->count(),
                // `null` es ILIMITADO, nunca cero.
                'maxUsers' => $limite,
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
            // Cuatro dígitos, y opcional: sólo lo necesita quien va a entrar a
            // la caja o a la cocina.
            'pin' => ['nullable', 'digits:4'],
        ]);

        $this->assertRoomInPlan();
        $this->assertEmailIsFree($data['email']);

        $role = $this->roleOrFail($data['role_code']);

        $user = DB::transaction(function () use ($data, $role): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                // Sin `Hash::make`: el modelo castea los dos como `hashed` y
                // hashear aquí guardaría el hash de un hash — nadie entraría, y
                // el fallo no diría por qué.
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
            // Cadena vacía = quitarle el PIN.
            'pin' => ['sometimes', 'nullable'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = User::with('roles')->find($id) ?? throw new NotFoundHttpException('Esa persona no está en tu equipo.');

        if (array_key_exists('is_active', $data)) {
            $this->assertNotSelf($user, 'Nadie se desactiva a sí mismo.');

            if ($data['is_active'] === false) {
                $this->assertNotLastOwner($user);
            }

            // Reactivar a alguien también ocupa una plaza del plan.
            if ($data['is_active'] === true && ! $user->is_active) {
                $this->assertRoomInPlan();
            }
        }

        $cambios = [];

        foreach (['name', 'is_active'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $cambios[$campo] = $data[$campo];
            }
        }

        if (isset($data['password'])) {
            // Sin `Hash::make`: lo castea el modelo.
            $cambios['password'] = $data['password'];
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

            $cambios['pin_hash'] = $pin === null || $pin === '' ? null : (string) $pin;
        }

        DB::transaction(function () use ($user, $cambios, $data): void {
            if ($cambios !== []) {
                $user->update($cambios);
            }

            if (isset($data['role_code'])) {
                $this->assertNotLastOwnerIfLosingIt($user, $data['role_code']);

                $role = $this->roleOrFail($data['role_code']);

                // Un rol por persona: dos roles con permisos que se solapan es
                // una pregunta sin respuesta clara cuando algo no se puede.
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

    /** Dar de baja: se desactiva, no se borra. */
    public function destroy(string $id): JsonResponse
    {
        $user = User::with('roles')->find($id) ?? throw new NotFoundHttpException('Esa persona no está en tu equipo.');

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
     * Los roles que este negocio puede repartir.
     *
     * Salen del catálogo base, no de una lista escrita en la pantalla: así el
     * día que aparezca un rol nuevo, aparece solo.
     *
     * @return list<array<string, string>>
     */
    private function availableRoles(): array
    {
        $existentes = DB::table('roles')->pluck('code')->all();

        return collect(RoleCatalog::all())
            ->filter(fn (array $_, string $code): bool => in_array($code, $existentes, true))
            ->map(fn (array $rol, string $code): array => ['code' => $code, 'name' => $rol['name']])
            ->values()
            ->all();
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

    /**
     * El techo del plan.
     *
     * Se cuentan sólo los ACTIVOS: alguien dado de baja hace tres meses no
     * puede seguir ocupando una plaza que se paga.
     */
    private function assertRoomInPlan(): void
    {
        $limite = $this->capabilities->get()->limits->maxUsers;

        if ($limite === null) {
            return;
        }

        if (User::where('is_active', true)->count() >= $limite) {
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
        // Dentro de ESTE negocio. El mismo correo en dos negocios es normal:
        // la misma persona puede tener dos locales.
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
     * Siempre queda un dueño.
     *
     * Un negocio sin dueño activo es un negocio que nadie puede configurar, y
     * desde dentro no hay forma de arreglarlo: haría falta que alguien entrara
     * por la base de datos.
     */
    private function assertNotLastOwner(User $user): void
    {
        if (! $user->isOwner()) {
            return;
        }

        $otros = User::where('is_active', true)
            ->where('id', '!=', $user->getKey())
            ->get()
            ->filter(fn (User $otro): bool => $otro->isOwner())
            ->count();

        if ($otros === 0) {
            throw new class('Tiene que quedar al menos un dueño activo.') extends UserError {};
        }
    }

    private function assertNotLastOwnerIfLosingIt(User $user, string $nuevoRol): void
    {
        $catalogo = RoleCatalog::all()[$nuevoRol] ?? null;

        if ($catalogo !== null && $catalogo['is_owner'] === false) {
            $this->assertNotLastOwner($user);
        }
    }
}
