<?php

declare(strict_types=1);

namespace Platform\Tenancy\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Platform\Tenancy\TenantContext;

/**
 * Adds `where tenant_id = ?` to every Eloquent query.
 *
 * CONVENIENCE, not the guarantee — that is RLS, which filters even when
 * somebody drops to raw SQL. It exists so queries read clearly in the logs and
 * so PostgreSQL uses the `tenant_id`-first indexes without depending on how the
 * planner reads the policy.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->has()) {
            // DENY, not allow. If this ever runs without context, the right answer is
            // "nothing", never "everything".
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->id());
    }
}
