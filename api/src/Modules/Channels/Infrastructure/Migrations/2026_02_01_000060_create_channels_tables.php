<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Los canales: WhatsApp y Telegram.
 *
 * Hay una decisión de fondo aquí que conviene entender antes de tocar nada.
 *
 * **Un mensaje que llega de Meta no trae subdominio.** Todo el sistema resuelve
 * el negocio a partir de la dirección desde la que se pidió la página, y un
 * webhook no tiene esa dirección: llega a una URL común, con el identificador
 * del número de WhatsApp dentro del cuerpo. Hay que saber de qué negocio es
 * ANTES de poder consultar nada suyo — y consultar sus tablas es justo lo que
 * RLS impide sin contexto.
 *
 * De ahí las dos tablas:
 *
 *   `channel_routes`    De plataforma, sin `tenant_id` propio y sin RLS. Es la
 *                       guía telefónica: «este número de WhatsApp es de este
 *                       negocio». Nada más. Ni credenciales, ni mensajes.
 *
 *   `channel_accounts`  De negocio, con RLS y las credenciales CIFRADAS. Sólo
 *                       se lee cuando ya se sabe de quién es.
 *
 * Podrían ser una sola tabla, y sería un error: las credenciales de todos los
 * negocios juntas en una tabla sin RLS es un fallo a un `where` de distancia.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * La guía telefónica de los webhooks.
         *
         * Es el equivalente exacto de `tenants.slug` para un mensaje: responde
         * «¿de quién es esto?» cuando todavía no hay negocio en contexto. Por
         * eso vive en la plataforma y no lleva RLS — igual que `tenants`.
         */
        Schema::create('channel_routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('channel');

            /*
             * Lo que identifica esta cuenta EN el canal: el `phone_number_id`
             * de Meta, o el identificador del bot de Telegram.
             *
             * Único de forma GLOBAL, no por negocio: un mismo número de
             * WhatsApp no puede pertenecer a dos negocios, y la unicidad tiene
             * que valer antes de saber de cuál es.
             */
            $table->string('external_id');

            $table->uuid('tenant_id');
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->unique(['channel', 'external_id'], 'uq_channel_routes_external');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        TenantSchema::create('channel_accounts', function (Blueprint $table): void {
            $table->string('channel');
            $table->string('external_id');

            // Cómo lo llama el dueño: «el WhatsApp del local».
            $table->string('label')->nullable();

            /*
             * CIFRADAS en la base, con el cast `encrypted` del modelo.
             *
             * No es una precaución teórica: aquí dentro va el token permanente
             * con el que se puede escribir a todos los clientes del negocio en
             * su nombre. Un volcado de la base que se filtre no puede ser
             * también una lista de tokens listos para usar.
             */
            $table->text('credentials')->nullable();

            /*
             * El secreto con el que se firma —o se comprueba— cada webhook.
             *
             * Aparte de las credenciales porque se consulta en cada mensaje que
             * entra, antes de hacer nada más.
             */
            $table->text('webhook_secret')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_message_at')->nullable();

            TenantSchema::uniquePerTenant($table, ['channel'], 'uq_channel_accounts_channel');
        });

        TenantSchema::create('conversations', function (Blueprint $table): void {
            $table->string('channel');

            // Con quién se habla, del lado del canal: el teléfono en WhatsApp,
            // el chat_id en Telegram.
            $table->string('external_chat_id');

            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();

            /*
             * Alguien del negocio tomó la conversación.
             *
             * Mientras esté puesto, el bot **se calla**. Es la salida que
             * necesita cualquier bot para no ser un muro: el cliente pide
             * hablar con una persona y deja de recibir menús automáticos
             * encima de lo que está escribiendo.
             */
            $table->boolean('is_human_takeover')->default(false);
            $table->timestampTz('takeover_at')->nullable();

            // Dónde se quedó la conversación: en el menú, viendo una categoría.
            $table->string('state')->default('idle');
            $table->jsonb('state_data')->default('{}');

            $table->timestampTz('last_message_at')->nullable();

            TenantSchema::uniquePerTenant($table, ['channel', 'external_chat_id'], 'uq_conversations_chat');
            TenantSchema::index($table, ['last_message_at'], 'idx_conversations_recientes');
        });

        TenantSchema::create('messages', function (Blueprint $table): void {
            TenantSchema::references($table, 'conversation_id', 'conversations', onDelete: 'cascade');

            // `in` lo escribió el cliente; `out` lo mandamos nosotros.
            $table->string('direction');

            $table->text('content')->nullable();
            $table->string('message_type')->default('text');

            /*
             * El identificador que le puso el canal.
             *
             * Se guarda para poder responder «esto ya lo procesamos» si Meta
             * reintenta el mismo mensaje — que lo hace, y más de lo que uno
             * espera.
             */
            $table->string('external_id')->nullable();

            $table->jsonb('metadata')->default('{}');

            TenantSchema::index($table, ['conversation_id', 'created_at'], 'idx_messages_conversacion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('channel_accounts');
        Schema::dropIfExists('channel_routes');
    }
};
