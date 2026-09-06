<?php

declare(strict_types=1);

return [

    /*
     * The root domain the tenants hang off: `elsazon.localhost` in
     * development, `elsazon.kombo.app` in production.
     *
     * The resolver extracts the subdomain against this value; a host that does
     * not end in it carries on with no tenant, which is what platform
     * administration needs.
     */
    'root_domain' => env('KOMBO_ROOT_DOMAIN', 'localhost'),

    /*
     * The platform administration domain. Not a tenant and never will be:
     * `admin` is on the resolver's reserved list.
     */
    'admin_host' => env('KOMBO_ADMIN_HOST', 'admin.localhost'),

    /*
     * Demo tooling (switching user without a password, seeding data).
     *
     * Its own flag rather than an APP_ENV check so it can be TESTED without
     * faking the environment — faking APP_ENV drags in the test CSRF exemption
     * and turns the test into a fight with the framework.
     */
    'demo_tools' => env('KOMBO_DEMO_TOOLS', env('APP_ENV') === 'local'),

    /*
     * How long the subdomain → tenant resolution is cached.
     *
     * The price is remembering to invalidate: anything that changes a tenant's
     * id or status must call TenantResolver::forget(). Otherwise the symptom
     * misleads — `/me` answers from cache while every query returns zero rows,
     * because RLS filters by an id that no longer exists.
     */
    'tenant_cache_ttl' => (int) env('KOMBO_TENANT_CACHE_TTL', 3600),

    /*
     * The public address of a tenant's portal, with `{slug}` where its own
     * goes.
     *
     * Needed because the bot's links are assembled outside an HTTP request:
     * `url()` in a queued job gives the address of the last job that ran.
     */
    'public_url' => env('KOMBO_PUBLIC_URL', 'http://{slug}.localhost:8010'),

    /*
     * Where backups are left on the server.
     *
     * Outside `storage/app` on purpose: that is what the backup packs up, so
     * each copy would contain the previous one and within weeks weigh more than
     * the data. In production it is a separate volume (see `compose.prod.yml`).
     */
    'backups' => [
        'path' => env('KOMBO_BACKUP_PATH', storage_path('backups')),
    ],

];
