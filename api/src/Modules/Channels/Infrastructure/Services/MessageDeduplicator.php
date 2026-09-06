<?php

declare(strict_types=1);

namespace Modules\Channels\Infrastructure\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Have we already processed this message? Meta retries — when the server is
 * slow, on any non-200, and sometimes for no apparent reason.
 *
 * `Cache::add()` and not `has()` then `put()`: two retries arriving at once
 * would both clear the `has()` before either wrote. `add()` is atomic in Redis.
 *
 * Kept 24 hours: longer than any retry window, and cheap at one key per message.
 */
final class MessageDeduplicator
{
    private const TTL_SECONDS = 86_400;

    /** `true` if this is the first time it has been seen. */
    public function firstTime(string $tenantId, string $channel, string $externalId): bool
    {
        if ($externalId === '') {
            // No id, nothing to deduplicate on. Let through rather than discarded:
            // losing a customer's message is worse than answering twice.
            return true;
        }

        return Cache::add("msg:{$tenantId}:{$channel}:{$externalId}", 1, self::TTL_SECONDS);
    }
}
