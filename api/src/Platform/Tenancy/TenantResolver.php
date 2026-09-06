<?php

declare(strict_types=1);

namespace Platform\Tenancy;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Platform\Tenancy\Exceptions\TenantNotFound;

/**
 * From the request host to the tenant: `elsazon.kombo.app` → slug `elsazon`.
 *
 * The first thing on every request, so it is cached: uncached it is one query
 * per request, and a WhatsApp bot sending messages makes that noticeable.
 */
final class TenantResolver
{
    /**
     * Subdomains the platform reserves.
     *
     * Without this list, somebody could register a tenant called `admin` and
     * take over the platform administration address.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'www', 'api', 'admin', 'app', 'panel', 'caja', 'cocina', 'portal',
        'mail', 'smtp', 'ftp', 'ns1', 'ns2', 'cdn', 'static', 'assets',
        'status', 'soporte', 'support', 'ayuda', 'blog', 'docs',
        'staging', 'dev', 'test', 'demo', 'internal',
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Cache $cache,
        private readonly string $rootDomain,
        private readonly int $ttlSeconds,
    ) {}

    /**
     * The slug in a host, or null when there is no tenant there.
     *
     * Null is not an error: it is the root domain (where sign-up lives) and
     * `admin.` (the platform).
     */
    public function slugFromHost(string $host): ?string
    {
        $host = strtolower(trim($host));

        // Strip the port: `elsazon.localhost:8010`.
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        $suffix = '.'.$this->rootDomain;

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        // Empty, or a nested subdomain (`something.elsazon.kombo.app`): no.
        if ($slug === '' || str_contains($slug, '.')) {
            return null;
        }

        if (in_array($slug, self::RESERVED, true)) {
            return null;
        }

        return $slug;
    }

    /**
     * @throws TenantNotFound
     */
    public function bySlug(string $slug): Tenant
    {
        $cached = $this->cache->get($this->cacheKey($slug));

        if (is_array($cached)) {
            return Tenant::fromRow($cached);
        }

        $row = $this->db->table('tenants')
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->first();

        if ($row === null) {
            // ABSENCE is deliberately not cached: a tenant that has just signed up has
            // to be able to get in immediately, not in an hour.
            throw new TenantNotFound($slug);
        }

        $tenant = Tenant::fromRow($row);

        $this->cache->put($this->cacheKey($slug), $tenant->toArray(), $this->ttlSeconds);

        return $tenant;
    }

    /**
     * Call on changing a tenant's plan, status or name.
     *
     * Forget it and the symptom misleads badly: `/me` answers correctly (from
     * cache) while every query returns zero rows, because RLS is filtering by
     * an id that no longer exists.
     */
    public function forget(string $slug): void
    {
        $this->cache->forget($this->cacheKey($slug));
    }

    private function cacheKey(string $slug): string
    {
        return "tenant:slug:{$slug}";
    }
}
