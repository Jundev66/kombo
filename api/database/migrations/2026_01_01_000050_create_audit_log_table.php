<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * La bitácora. De sólo inserción, y no por convención.
 *
 * Registra quién hizo qué, cuándo, desde dónde y con la autorización de quién.
 * Existe sobre todo para dos conversaciones incómodas: «falta dinero en la
 * caja» y «yo no anulé ese pedido».
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('audit_log', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable();

            // El nombre COPIADO, no referenciado. Si mañana se borra el
            // usuario, la bitácora tiene que seguir diciendo quién fue.
            $table->string('user_name')->nullable();

            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->uuid('entity_id')->nullable();

            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->text('reason')->nullable();

            // Quién autorizó con su PIN, cuando la acción lo exigía. Van los
            // dos campos: un identificador sin nombre no se puede leer, y un
            // nombre sin identificador no se puede rastrear.
            $table->uuid('authorized_by')->nullable();
            $table->string('authorized_by_name')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('device_id')->nullable();

            $table->boolean('is_support_access')->default(false);
            $table->string('support_case')->nullable();

            TenantSchema::index($table, ['created_at'], 'idx_audit_tenant_created');
            TenantSchema::index($table, ['action'], 'idx_audit_tenant_action');
            TenantSchema::index($table, ['entity_type', 'entity_id'], 'idx_audit_tenant_entity');
        });

        // ── Y aquí está lo que hace REAL la inmutabilidad ────────────────────
        //
        // El usuario con el que conecta la aplicación puede INSERTAR y LEER
        // esta tabla, y nada más. Ni el código, ni un error, ni alguien con
        // acceso a la aplicación puede modificar el histórico.
        //
        // Es la única parte del sistema donde un privilegio de PostgreSQL hace
        // un trabajo que el código no puede hacer solo — y la segunda razón
        // por la que hay dos usuarios de base de datos.
        DB::statement('revoke update, delete on audit_log from '.self::appUser());
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }

    private static function appUser(): string
    {
        // Del entorno, no fijo: en CI y en producción el usuario puede
        // llamarse distinto, y una migración que asume un nombre falla tarde.
        return (string) config('database.connections.pgsql.username', 'kombo_app');
    }
};
