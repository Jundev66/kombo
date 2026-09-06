<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Roles and permissions, without `spatie/laravel-permission`, for concrete
 * reasons:
 *
 * - There is no `permissions` table: permissions come from each module's
 *   manifest, so a switched-off module's simply do not exist. A table would
 *   make them data to synchronise on every deployment.
 * - The owner is `is_owner`, not "every permission assigned": `['*']` expanded
 *   against whichever modules the tenant has on today.
 * - There is a third state spatie does not model: `requires_authorization`.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('roles', function (Blueprint $table): void {
            $table->string('code');
            $table->string('name');

            // System roles arrive with the industry pack at sign-up and cannot be
            // edited or deleted; the owner can create their own on top.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_owner')->default(false);

            TenantSchema::uniquePerTenant($table, ['code'], 'uq_roles_tenant_code');
        });

        TenantSchema::create('role_permissions', function (Blueprint $table): void {
            TenantSchema::references($table, 'role_id', 'roles', onDelete: 'cascade');

            // Text, not a foreign key: there is no `permissions` table.
            $table->string('permission');

            // The third state. `false` = they can carry it out alone; `true` = they can
            // start it, and someone else's PIN is required.
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
