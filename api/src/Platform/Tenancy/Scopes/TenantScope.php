<?php

declare(strict_types=1);

namespace Platform\Tenancy\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Platform\Tenancy\TenantContext;

/**
 * Añade `where tenant_id = ?` a toda consulta de Eloquent.
 *
 * Es COMODIDAD, no la garantía. La garantía es Row Level Security, que filtra
 * aunque alguien se salte esto con SQL crudo. Existe por dos razones
 * prácticas: hace que las consultas sean legibles en los logs, y permite que
 * PostgreSQL use los índices que empiezan por `tenant_id` sin depender de cómo
 * el planificador interprete la política.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->has()) {
            // NEGAR, no permitir. Si por lo que sea esto corre sin contexto,
            // la respuesta correcta es "nada", nunca "todo".
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->id());
    }
}
