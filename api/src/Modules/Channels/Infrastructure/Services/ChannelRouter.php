<?php

declare(strict_types=1);

namespace Modules\Channels\Infrastructure\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Which tenant does this webhook belong to?
 *
 * The same question the subdomain answers elsewhere, but a message from Meta
 * carries none — and it has to be answered before anything of the tenant can be
 * queried, because without context RLS correctly returns zero rows.
 *
 * Cached, since this runs on every incoming message. Whoever changes a route
 * has to call `forget()`, or messages get processed against the wrong tenant.
 */
final class ChannelRouter
{
    private const TTL = 3600;

    /**
     * "I do not know it" is cached for only a few seconds.
     *
     * Caching absence for an hour would leave a tenant that just connected its
     * channel an hour without a single message, with no error in sight. Ten
     * seconds slows a script down just as well.
     */
    private const TTL_AUSENCIA = 10;

    /** The tenant that owns that account, or null if nobody knows it. */
    public function tenantFor(string $channel, string $externalId): ?string
    {
        $key = self::key($channel, $externalId);

        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $tenantId = (string) (DB::table('channel_routes')
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->where('is_active', true)
            ->value('tenant_id') ?? '');

        Cache::put($key, $tenantId, $tenantId === '' ? self::TTL_AUSENCIA : self::TTL);

        return $tenantId === '' ? null : $tenantId;
    }

    /**
     * Registers or updates an account's route: writes the platform table and
     * clears the cache in one gesture, since the two cannot fall out of step.
     */
    public function register(string $channel, string $externalId, string $tenantId): void
    {
        DB::table('channel_routes')->updateOrInsert(
            ['channel' => $channel, 'external_id' => $externalId],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->forget($channel, $externalId);
    }

    public function forget(string $channel, string $externalId): void
    {
        Cache::forget(self::key($channel, $externalId));
    }

    private static function key(string $channel, string $externalId): string
    {
        return "channel_route:{$channel}:{$externalId}";
    }
}
