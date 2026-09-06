<?php

declare(strict_types=1);

namespace Platform\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Platform\Tenancy\Scopes\TenantScope;
use Platform\Tenancy\TenantContext;

/**
 * What makes a model belong to a tenant: fills `tenant_id` on create and
 * filters on query, so no use case has to remember to.
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
     * Query across tenants.
     *
     * For the platform dashboard and the isolation tests; forbidden inside
     * `src/Modules/`, and an architecture test verifies it.
     *
     * It only drops Eloquent's filter. RLS is still in place, so as `kombo_app`
     * this returns exactly the same rows — which is what one isolation test
     * checks: that the second defence holds alone.
     */
    public static function acrossTenants(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }
}
