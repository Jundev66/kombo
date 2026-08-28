<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los negocios. Tabla de PLATAFORMA, y sin RLS por una razón de huevo y
 * gallina: se consulta para AVERIGUAR de qué negocio hablamos, así que no
 * puede filtrarse por el negocio en contexto.
 *
 * Es la única tabla de la que cuelga todo lo demás, y dar de alta un cliente
 * es exactamente una fila aquí: ni DNS, ni certificado, ni despliegue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // El subdominio. Único en todo el sistema, en minúsculas, y con
            // una lista de reservados en el resolutor (`admin`, `www`, `api`…)
            // para que nadie registre un negocio que secuestre una dirección
            // de la plataforma.
            $table->string('slug')->unique();
            $table->string('name');

            $table->string('plan_code');
            $table->string('status')->default('trial');

            $table->string('tax_id')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();

            $table->string('timezone')->default('America/Caracas');
            $table->char('country_code', 2)->default('VE');

            $table->timestampTz('trial_ends_at')->nullable();

            // A los 90 días de dejar de pagar. El negocio pasa a sólo lectura
            // y puede exportarlo todo antes de esa fecha; borrar sin aviso los
            // datos de alguien que confió en el sistema no es una opción.
            $table->timestampTz('data_expires_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
            $table->foreign('plan_code')->references('code')->on('plans')->restrictOnDelete();
        });

        // Los valores permitidos se comprueban con un CHECK y no con un tipo
        // ENUM de PostgreSQL: agregar un valor a un ENUM es una migración con
        // bloqueo de tabla, y estos estados van a crecer.
        DB::statement("alter table tenants add constraint chk_tenants_status
            check (status in ('trial','active','past_due','suspended','closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
