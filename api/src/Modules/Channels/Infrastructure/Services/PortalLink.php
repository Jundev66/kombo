<?php

declare(strict_types=1);

namespace Modules\Channels\Infrastructure\Services;

/**
 * The public address of a tenant's portal.
 *
 * Here rather than `url()` because the bot's links are assembled outside an
 * HTTP request: a notice leaves from the queue, where `url()` would give the
 * address of the last job that ran — possibly a different tenant's.
 */
final class PortalLink
{
    public static function forTenant(string $slug, string $path = '/'): string
    {
        $template = (string) config('kombo.public_url', 'http://{slug}.localhost:8010');

        return rtrim(str_replace('{slug}', $slug, $template), '/').$path;
    }
}
