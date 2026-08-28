<?php

declare(strict_types=1);

namespace Platform\Audit;

use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Platform\Tenancy\TenantContext;

/**
 * La bitácora. **Sólo inserta.**
 *
 * No hay método para actualizar ni para borrar, y no por disciplina: el
 * usuario con el que conecta la aplicación tiene INSERT y SELECT sobre
 * `audit_log` y nada más. Aunque alguien escribiera aquí un `update`, la base
 * lo rechazaría.
 *
 * Se registra para dos conversaciones que ocurren de verdad: «falta dinero en
 * la caja» y «yo no anulé ese pedido». Las dos se resuelven mal sin saber
 * quién, cuándo y con la autorización de quién.
 */
final class AuditLogger
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TenantContext $context,
        private readonly Auth $auth,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?AuthorizedBy $authorizedBy = null,
        ?Actor $actor = null,
    ): void {
        if (! $this->context->has()) {
            return;
        }

        $user = $this->auth->guard()->user();

        $this->db->table('audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->context->id(),

            // El actor explícito gana sobre quien autenticó la petición: en la
            // caja, el token es del dispositivo y quien opera es la persona
            // que puso su PIN.
            'user_id' => $actor?->userId ?? $user?->getAuthIdentifier(),
            'user_name' => $actor?->userName ?? $user?->name,

            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,

            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'reason' => $reason,

            'authorized_by' => $authorizedBy?->userId,
            'authorized_by_name' => $authorizedBy?->userName,

            'ip_address' => $this->request->ip(),
            'device_id' => $this->request->header('X-Kombo-Device'),

            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
