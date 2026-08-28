<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Los usuarios. **Tabla de negocio**, no de plataforma.
 *
 * Un usuario pertenece a UN negocio, y el correo es único DENTRO de ese
 * negocio. Es lo que permite que la misma persona —o el mismo correo
 * genérico— exista en dos negocios sin colisionar, y que al iniciar sesión no
 * haya que preguntar «¿a cuál de tus negocios?»: el subdominio ya lo dijo.
 *
 * Se crea con TenantSchema, así que trae `tenant_id`, RLS activado y forzado,
 * su política de aislamiento y el único `(tenant_id, id)` sin que haya que
 * acordarse de nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('users', function (Blueprint $table): void {
            $table->string('name');
            $table->string('email');
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');

            // PIN de operación, con hash. La caja y la cocina son máquinas
            // compartidas del local: nadie va a escribir un correo y una
            // contraseña larga con las manos ocupadas y un cliente esperando.
            // Y sirve además para autorizar acciones sensibles a nombre de
            // quien las autoriza, sin cerrar la sesión de quien las inicia.
            $table->string('pin_hash')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();

            TenantSchema::uniquePerTenant($table, ['email'], 'uq_users_tenant_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
