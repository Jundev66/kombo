<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Roles y permisos. Sin `spatie/laravel-permission`, y por razones concretas:
 *
 * - **No hay tabla `permissions`.** Los permisos los aporta el manifiesto de
 *   cada módulo, así que los de un módulo apagado sencillamente no existen en
 *   el sistema. Una tabla los volvería datos que hay que sincronizar en cada
 *   despliegue y que quedan huérfanos cuando un módulo se va.
 * - **El dueño no se modela con «todos los permisos asignados»** sino con
 *   `is_owner`, que devuelve `['*']` y se expande contra los módulos que el
 *   negocio tenga encendidos hoy.
 * - **Existe un tercer estado** que spatie no modela: `requires_authorization`
 *   — puedes INICIAR la acción, pero se ejecuta con el PIN de otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('roles', function (Blueprint $table): void {
            $table->string('code');
            $table->string('name');

            // Los roles de sistema los trae el paquete del negocio al
            // registrarse y no se pueden editar ni borrar; el dueño puede
            // crear los suyos encima.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_owner')->default(false);

            TenantSchema::uniquePerTenant($table, ['code'], 'uq_roles_tenant_code');
        });

        TenantSchema::create('role_permissions', function (Blueprint $table): void {
            TenantSchema::references($table, 'role_id', 'roles', onDelete: 'cascade');

            // Texto, no clave foránea: no existe tabla `permissions`.
            $table->string('permission');

            // El tercer estado. `false` = puede ejecutarlo solo.
            // `true` = puede iniciarlo, y hace falta el PIN de alguien que sí.
            $table->boolean('requires_authorization')->default(false);

            TenantSchema::uniquePerTenant($table, ['role_id', 'permission'], 'uq_role_permissions');
        });

        TenantSchema::create('role_user', function (Blueprint $table): void {
            TenantSchema::references($table, 'user_id', 'users', onDelete: 'cascade');
            TenantSchema::references($table, 'role_id', 'roles', onDelete: 'cascade');

            TenantSchema::uniquePerTenant($table, ['user_id', 'role_id'], 'uq_role_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
