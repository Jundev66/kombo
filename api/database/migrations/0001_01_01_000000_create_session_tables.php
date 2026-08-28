<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones y recuperación de contraseña.
 *
 * Son tablas de PLATAFORMA —están declaradas en TenantSchema::PLATFORM_TABLES—
 * y por eso no llevan `tenant_id` ni RLS: la sesión se resuelve antes de saber
 * de qué negocio hablamos.
 *
 * La tabla `users` NO está aquí: es una tabla de negocio y se crea con
 * TenantSchema, en su propia migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // Ojo para cuando se implemente recuperar contraseña: la clave es el
        // correo, y en este sistema el mismo correo puede existir en dos
        // negocios distintos. Habrá que añadir el negocio a la clave, o
        // resolverlo por el subdominio desde el que se pidió.
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
    }
};
