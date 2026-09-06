<?php

declare(strict_types=1);

namespace Platform\Audit;

use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Platform\Tenancy\TenantContext;

/**
 * The audit log. Insert only.
 *
 * Not by discipline: the application's database user has INSERT and SELECT on
 * `audit_log` and nothing else, so even an `update` written here is refused.
 *
 * It exists for two conversations that really happen: "the till is short" and
 * "I did not void that order".
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

            // An explicit actor beats whoever authenticated the request: at the till
            // the token is the device's and the operator is whoever entered their PIN.
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
