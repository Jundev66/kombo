<?php

declare(strict_types=1);

namespace Platform\Tenancy\Database;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo se crea una tabla de negocio, para que sea imposible hacerlo mal.
 *
 * El aislamiento entre negocios no se sostiene con buenas intenciones ni con
 * revisiones de código. Se sostiene con cuatro cosas en CADA tabla:
 *
 *   1. Columna `tenant_id`.
 *   2. Row Level Security activado Y FORZADO.
 *   3. Una política `tenant_isolation` que filtra por el negocio en contexto.
 *   4. Índices que empiezan por `tenant_id` y claves foráneas COMPUESTAS.
 *
 * Acordarse de las cuatro, en cada migración, para siempre, no es un plan.
 * Por eso existe esta clase: se llama a `create()` y las cuatro salen solas.
 * Y por si alguien crea una tabla a mano, `SchemaGuardTest` recorre el
 * catálogo de PostgreSQL y falla si encuentra una que se saltó la regla.
 */
final class TenantSchema
{
    /**
     * El nombre del parámetro de sesión de PostgreSQL donde vive el negocio en
     * curso. Lo escribe TenantDatabaseGuard y lo lee la política de RLS.
     */
    public const GUC = 'app.tenant_id';

    /**
     * Tablas que NO llevan `tenant_id` y por tanto no llevan RLS.
     *
     * Son de dos clases: las de infraestructura de Laravel, y las de la
     * plataforma —que se consultan ANTES de saber de qué negocio hablamos, así
     * que filtrar por negocio sería un problema de huevo y gallina—.
     *
     * Esta lista es explícita a propósito. Añadir algo aquí debería doler un
     * poco: es declarar que esa tabla queda fuera del aislamiento.
     *
     * @var list<string>
     */
    public const PLATFORM_TABLES = [
        // Infraestructura de Laravel
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',

        // Plataforma: se consultan sin contexto de negocio
        'plans',
        'plan_modules',
        'tenants',
        'tenant_domains',
        'subscriptions',
        'subscription_payments',
        'platform_users',
        'platform_audit_log',

        /*
         * La guía telefónica de los webhooks.
         *
         * Un mensaje que llega de Meta no trae subdominio: llega a una URL
         * común con el identificador del número dentro. Hay que saber de qué
         * negocio es ANTES de poder consultar nada suyo, que es justo lo que
         * RLS impide sin contexto. Aquí no hay ni credenciales ni mensajes:
         * sólo «este número es de este negocio».
         */
        'channel_routes',
    ];

    /**
     * Índices únicos que a propósito NO empiezan por `tenant_id`, con la razón.
     *
     * El comentario no es decorativo: es lo que se lee cuando alguien pregunta
     * "¿y por qué este se salta la regla?" dentro de dos años.
     *
     * @var array<string, string>
     */
    public const GLOBAL_UNIQUE_INDEXES = [
        'personal_access_tokens_token_unique' => 'Es el hash de un token de acceso. Un token tiene que resolver a un único usuario ANTES de saber de qué negocio es —esa es justo la información que trae—, así que la unicidad debe ser global.',
    ];

    /**
     * Crea una tabla de negocio con todo lo obligatorio ya puesto.
     *
     * Aporta: `id` uuid como clave primaria, `tenant_id`, marcas de tiempo con
     * zona horaria, el único `(tenant_id, id)` —sin el cual las claves foráneas
     * compuestas serían imposibles— y RLS activado, forzado y con su política.
     */
    public static function create(string $table, Closure $definition): void
    {
        Schema::create($table, function (Blueprint $blueprint) use ($definition): void {
            $blueprint->uuid('id')->primary();
            $blueprint->uuid('tenant_id');

            $definition($blueprint);

            $blueprint->timestampsTz();

            // Este único es el que hace posibles las FK compuestas de abajo.
            $blueprint->unique(['tenant_id', 'id'], "uq_{$blueprint->getTable()}_tenant_id");
        });

        self::enableRowLevelSecurity($table);
    }

    /**
     * Una clave foránea a otra tabla de negocio. SIEMPRE compuesta.
     *
     * `(tenant_id, columna) → (tenant_id, id)` en vez de `columna → id`.
     *
     * La diferencia es la que impide, a nivel de base de datos, meter en el
     * pedido de un negocio el producto de otro. Con una FK simple eso es una
     * fila perfectamente válida y el error se descubre meses después, cuando
     * un reporte no cuadra.
     */
    public static function references(
        Blueprint $table,
        string $column,
        string $referencedTable,
        bool $nullable = false,
        string $onDelete = 'restrict',
    ): void {
        $definition = $table->uuid($column);

        if ($nullable) {
            $definition->nullable();
        }

        $table->foreign(['tenant_id', $column], "fk_{$table->getTable()}_{$column}")
            ->references(['tenant_id', 'id'])
            ->on($referencedTable)
            ->onDelete($onDelete);
    }

    /**
     * Un índice que empieza por `tenant_id`.
     *
     * El orden importa y no es una preferencia estética: toda consulta lleva
     * `where tenant_id = ?`, así que un índice que no empiece por ahí obliga a
     * PostgreSQL a recorrer filas de otros negocios para descartarlas. En una
     * máquina modesta con un año de pedidos, eso es la diferencia entre que el
     * tablero de cocina cargue en 40 ms o en dos segundos.
     */
    public static function index(Blueprint $table, array $columns, ?string $name = null): void
    {
        $table->index(['tenant_id', ...$columns], $name);
    }

    /**
     * Un único por negocio: dos negocios pueden tener el mismo código de
     * producto sin pisarse.
     */
    public static function uniquePerTenant(Blueprint $table, array $columns, ?string $name = null): void
    {
        $table->unique(['tenant_id', ...$columns], $name);
    }

    /**
     * La política de aislamiento. Se copia palabra por palabra; cada parte está
     * por una razón distinta.
     */
    public static function enableRowLevelSecurity(string $table): void
    {
        DB::statement("alter table {$table} enable row level security");

        // FORCE es imprescindible: sin él, el DUEÑO de la tabla se salta la
        // política sin avisar. Y el dueño de la tabla es quien corre las
        // migraciones y los seeders.
        DB::statement("alter table {$table} force row level security");

        // USING protege la lectura. WITH CHECK protege la escritura: impide
        // INSERTAR una fila marcada como de otro negocio, que es un agujero
        // distinto y que USING solo no tapa.
        //
        // nullif(current_setting(..., true), '') cubre los dos casos en que no
        // hay negocio en contexto —parámetro sin fijar (null) y fijado en
        // cadena vacía, que es como queda tras limpiar la conexión—. En ambos
        // la comparación da null, y null no es true: CERO filas.
        //
        // Es decir: EL MODO DE FALLO ES NEGAR. Si algo se rompe, no se ve nada;
        // nunca se ve de más.
        DB::statement(<<<SQL
            create policy tenant_isolation on {$table}
                for all
                using (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
                with check (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)
        SQL);
    }
}
