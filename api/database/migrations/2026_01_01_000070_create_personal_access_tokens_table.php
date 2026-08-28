<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Los tokens de acceso, en versión propia y como TABLA DE NEGOCIO.
 *
 * Reemplaza la que trae Sanctum. El token de la tablet de una cocina pertenece
 * a ese negocio y a ninguno más: con la tabla original, un token válido serviría
 * en cualquier subdominio.
 *
 * Hay una excepción declarada: el índice único sobre el hash del token es
 * GLOBAL y no por negocio. Tiene que serlo — un token debe resolver a un único
 * usuario ANTES de saber de qué negocio es, porque esa es justamente la
 * información que trae. Está en TenantSchema::GLOBAL_UNIQUE_INDEXES con su
 * razón, y por eso SchemaGuardTest no protesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('personal_access_tokens', function (Blueprint $table): void {
            // Declarados a mano y NO con `uuidMorphs()`, para que el índice lo
            // ponga TenantSchema con `tenant_id` delante.
            $table->uuid('tokenable_id');
            $table->string('tokenable_type');

            $table->text('name');
            $table->string('token', 64)->unique();

            // 'device'  → sólo pedir la lista de nombres y validar un PIN.
            // 'station' → operar, a nombre de la persona que puso su PIN.
            $table->text('abilities')->nullable();

            $table->string('device_id')->nullable();
            $table->timestampTz('last_used_at')->nullable();

            // Sin caducidad por reloj: una caja puede pasar el día entero sin
            // que nadie la toque y no puede quedarse fuera a media venta. Se
            // revocan explícitamente, que es más honesto que un cierre de
            // sesión sorpresa a las siete de la tarde.
            $table->timestampTz('expires_at')->nullable();

            TenantSchema::index($table, ['tokenable_type', 'tokenable_id'], 'idx_pat_tenant_tokenable');
            TenantSchema::index($table, ['expires_at'], 'idx_pat_tenant_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
