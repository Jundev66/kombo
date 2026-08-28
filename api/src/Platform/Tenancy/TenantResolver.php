<?php

declare(strict_types=1);

namespace Platform\Tenancy;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Platform\Tenancy\Exceptions\TenantNotFound;

/**
 * Del host de la petición al negocio.
 *
 * `elsazon.kombo.app` → el negocio con slug `elsazon`.
 *
 * Es la primera cosa que ocurre en cada petición, así que va cacheada: sin
 * caché es una consulta por petición, y con un bot de WhatsApp mandando
 * mensajes eso se nota.
 */
final class TenantResolver
{
    /**
     * Subdominios que la plataforma se reserva.
     *
     * Sin esta lista, alguien podría registrar un negocio llamado `admin` y
     * quedarse con la dirección de la super administración. No es hipotético:
     * es lo primero que probaría cualquiera que quisiera hacer daño.
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
     * El slug que hay en un host, o null si ahí no hay negocio.
     *
     * Devolver null no es un error: es lo que pasa en el dominio raíz (donde
     * vive el registro de negocios nuevos) y en `admin.` (la plataforma).
     */
    public function slugFromHost(string $host): ?string
    {
        $host = strtolower(trim($host));

        // Quitar el puerto: `elsazon.localhost:8010`.
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        $suffix = '.'.$this->rootDomain;

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        // Vacío, o un subdominio anidado (`algo.elsazon.kombo.app`): no.
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
            // La AUSENCIA no se cachea a propósito: un negocio que acaba de
            // darse de alta tiene que poder entrar en el acto, no dentro de
            // una hora.
            throw new TenantNotFound($slug);
        }

        $tenant = Tenant::fromRow($row);

        $this->cache->put($this->cacheKey($slug), $tenant->toArray(), $this->ttlSeconds);

        return $tenant;
    }

    /**
     * Hay que llamarlo al cambiar el plan, el estado o el nombre de un negocio.
     *
     * Si se olvida, el síntoma engaña de la peor manera: `/me` responde bien
     * —viene de caché— y todas las consultas devuelven cero filas, porque RLS
     * está filtrando por un identificador que ya no existe.
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
