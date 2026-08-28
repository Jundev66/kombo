<?php

declare(strict_types=1);

namespace Platform\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Platform\Tenancy\Scopes\TenantScope;
use Platform\Tenancy\TenantContext;

/**
 * Lo que hace que un modelo pertenezca a un negocio.
 *
 * Rellena `tenant_id` al crear y filtra al consultar, para que ningún caso de
 * uso tenga que acordarse. Acordarse, en cada consulta, para siempre, no es un
 * plan.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', app(TenantContext::class)->id());
            }
        });
    }

    /**
     * Consultar por encima de los negocios.
     *
     * Existe para el panel de plataforma y para las pruebas de aislamiento, y
     * **está prohibido dentro de `src/Modules/`** — hay una prueba de
     * arquitectura que lo verifica.
     *
     * Ojo: esto sólo quita el filtro de Eloquent. Row Level Security sigue
     * puesta, así que como `kombo_app` esto devuelve exactamente lo mismo. Es
     * justo lo que comprueba una de las pruebas de aislamiento: que la segunda
     * defensa aguanta sola cuando se desactiva la primera.
     */
    public static function acrossTenants(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }
}
