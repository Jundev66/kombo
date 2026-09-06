<?php

declare(strict_types=1);

namespace Platform\Subscription;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a platform administrator does, written where it can be read.
 *
 * A log SEPARATE from each tenant's: this is what WE do in a customer's house —
 * suspend them, change their plan, look at their data — and it has to be
 * answerable when they ask who touched something.
 *
 * The NAME is stored alongside the id: when an administrator leaves and their
 * account is deleted, the record still has to say who it was.
 */
final class PlatformAudit
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, ?string $tenantId = null, array $details = []): void
    {
        $user = auth('platform')->user();

        DB::table('platform_audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'platform_user_id' => $user?->getKey(),
            'platform_user_name' => $user?->name,
            'action' => $action,
            'tenant_id' => $tenantId,
            'details' => json_encode($details),
            'ip' => request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * What was done in a tenant's house, so it can be shown to them.
     *
     * @return list<array<string, mixed>>
     */
    public function forTenant(string $tenantId, int $limit = 50): array
    {
        return DB::table('platform_audit_log')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'action' => $row->action,
                'by' => $row->platform_user_name,
                'at' => $row->created_at,
                'details' => json_decode((string) $row->details, true),
            ])
            ->all();
    }

    /** The request origin, recorded exactly as it arrived. */
    public static function ipOf(Request $request): ?string
    {
        return $request->ip();
    }
}
