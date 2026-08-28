<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La plataforma cobrándose a sí misma.
 *
 * Todo esto es **global**: no lleva `tenant_id` ni RLS, porque la pregunta que
 * responde —«¿este negocio está al día?»— hay que poder contestarla mirando a
 * todos los negocios a la vez. Está declarado en `TenantSchema::PLATFORM_TABLES`
 * con esa razón.
 *
 * Y hay un campo que manda sobre todos los demás: **`current_period_end`**. No
 * hay un `is_paid`, ni un `expired`, ni una bandera que alguien tenga que
 * acordarse de mover. Hay una fecha, y un trabajo diario que la mira. Ese es
 * justo el hueco que quedó abierto en el proyecto anterior: allí existía un
 * `plan_expires_at` que no leía nadie.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Los super administradores.
         *
         * Tabla aparte de `users`, y no una bandera `is_super` sobre ella. Un
         * usuario de negocio y un administrador de plataforma no son la misma
         * cosa con un permiso más: entran por sitios distintos, ven cosas
         * distintas, y confundirlos es cómo se acaba dando acceso a la
         * facturación de todos los clientes al empleado de uno.
         */
        Schema::create('platform_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Una suscripción viva por negocio. La historia queda en los pagos.
            $table->uuid('tenant_id')->unique();
            $table->string('plan_code');

            $table->string('status')->default('trial');

            $table->timestampTz('started_at');

            /*
             * **El único campo que decide.**
             *
             * Hasta cuándo está pagado. Un trabajo diario lo mira: avisa antes,
             * marca vencido al pasarse, y suspende al agotarse la gracia.
             */
            $table->timestampTz('current_period_end');

            /*
             * Cuántos días se aguantan después del vencimiento.
             *
             * Por suscripción y no fijo en el código: a un cliente de años se
             * le esperan quince días, y a uno que lleva un mes, tres. Esa
             * decisión es del que cobra, no del que programa.
             */
            $table->integer('grace_days')->default(5);

            $table->timestampTz('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('plan_code')->references('code')->on('plans')->restrictOnDelete();

            // La consulta del trabajo diario: «cuáles vencen pronto o ya
            // vencieron», ordenadas por fecha.
            $table->index(['current_period_end', 'status'], 'idx_subscriptions_vencimiento');
        });

        /*
         * Los pagos, registrados a mano.
         *
         * Aquí se cobra por pago móvil y por transferencia, y no hay pasarela
         * que avise. Alguien mira su cuenta, ve que entró, y lo anota. Fingir
         * un cobro automático que no existe sería peor que asumirlo.
         */
        Schema::create('subscription_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id');
            $table->uuid('subscription_id');

            $table->bigInteger('amount_cents');
            $table->char('currency', 3)->default('USD');
            $table->string('method');
            $table->string('reference')->nullable();

            $table->timestampTz('paid_at');

            // Qué período cubre. Se guarda además de mover
            // `current_period_end` porque el importe de un mes no se deduce de
            // una fecha: hubo meses con descuento y meses de dos.
            $table->date('period_from');
            $table->date('period_to');

            $table->string('receipt_url')->nullable();
            $table->uuid('registered_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();

            $table->index(['tenant_id', 'paid_at'], 'idx_subscription_payments_negocio');
        });

        /*
         * La bitácora de la PLATAFORMA.
         *
         * Aparte de `audit_log`, que es de cada negocio y con RLS. Aquí va lo
         * que hace un administrador: dar de alta, suspender, registrar un pago,
         * mirar los datos de un cliente. Mezclarlas sería o dejar sin RLS lo del
         * negocio, o esconderle al negocio lo que hicimos en su casa.
         */
        Schema::create('platform_audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('platform_user_id')->nullable();
            $table->string('platform_user_name')->nullable();

            $table->string('action');
            $table->uuid('tenant_id')->nullable();
            $table->jsonb('details')->default('{}');
            $table->string('ip', 45)->nullable();

            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'created_at'], 'idx_platform_audit_negocio');
            $table->index('created_at', 'idx_platform_audit_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_log');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('platform_users');
    }
};
