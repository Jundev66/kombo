<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * The channels: WhatsApp and Telegram.
 *
 * A message from Meta carries no subdomain — it arrives at a shared URL with
 * the number's id in the body — so we must know whose it is BEFORE querying
 * anything of theirs, which is what RLS prevents without context. Hence two
 * tables:
 *
 *   `channel_routes`    platform-level, no RLS. The phone book: "this number
 *                       belongs to this tenant". No credentials, no messages.
 *   `channel_accounts`  tenant-level, RLS, credentials ENCRYPTED. Only read
 *                       once we know whose it is.
 *
 * One table would put every tenant's credentials one `where` away.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The webhooks' phone book: the exact equivalent of `tenants.slug` for
         * a message, answering "whose is this?" before there is any context.
         */
        Schema::create('channel_routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('channel');

            /*
             * What identifies this account ON the channel: Meta's
             * `phone_number_id`, or the Telegram bot's id. Unique GLOBALLY, not
             * per tenant — the same number cannot belong to two tenants, and
             * that has to hold before we know whose it is.
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

            // What the owner calls it: "the shop's WhatsApp".
            $table->string('label')->nullable();

            /*
             * ENCRYPTED, via the model's `encrypted` cast. What goes in here is
             * the permanent token that can write to every customer in the
             * tenant's name: a leaked dump must not also be a list of
             * ready-to-use tokens.
             */
            $table->text('credentials')->nullable();

            /*
             * The secret each webhook is signed with. Kept apart from the
             * credentials because it is read on every incoming message.
             */
            $table->text('webhook_secret')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_message_at')->nullable();

            TenantSchema::uniquePerTenant($table, ['channel'], 'uq_channel_accounts_channel');
        });

        TenantSchema::create('conversations', function (Blueprint $table): void {
            $table->string('channel');

            // Who is being talked to, on the channel's side: the phone number on
            // WhatsApp, the chat_id on Telegram.
            $table->string('external_chat_id');

            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();

            /*
             * Somebody from the tenant took the conversation over; while it is
             * set, the bot goes quiet. The escape hatch any bot needs so as not
             * to be a wall.
             */
            $table->boolean('is_human_takeover')->default(false);
            $table->timestampTz('takeover_at')->nullable();

            // Where the conversation got to: at the menu, viewing a category.
            $table->string('state')->default('idle');
            $table->jsonb('state_data')->default('{}');

            $table->timestampTz('last_message_at')->nullable();

            TenantSchema::uniquePerTenant($table, ['channel', 'external_chat_id'], 'uq_conversations_chat');
            TenantSchema::index($table, ['last_message_at'], 'idx_conversations_recientes');
        });

        TenantSchema::create('messages', function (Blueprint $table): void {
            TenantSchema::references($table, 'conversation_id', 'conversations', onDelete: 'cascade');

            // `in` was written by the customer; `out` was sent by us.
            $table->string('direction');

            $table->text('content')->nullable();
            $table->string('message_type')->default('text');

            /*
             * The id the channel gave it, stored so we can answer "already
             * processed" when Meta retries — which it does, more than you would
             * expect.
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
