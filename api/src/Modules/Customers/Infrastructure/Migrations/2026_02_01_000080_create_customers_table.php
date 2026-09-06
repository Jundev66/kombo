<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * The tenant's customers, identified by phone number.
 *
 * No foreign key from `orders`, deliberately: orders already store name and
 * phone as copies, so an old order reads in full even if the customer is
 * deleted — and `Orders` never learns this module exists.
 *
 * The phone number is ENCRYPTED, as in `users`.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('customers', function (Blueprint $table): void {
            /*
             * An encrypted phone cannot be indexed or matched by equality —
             * Laravel's encryption is not deterministic — so a hash goes
             * alongside: enough to find the customer without reading the number.
             */
            $table->text('phone');
            $table->string('phone_hash', 64);

            $table->string('name')->nullable();
            $table->text('notes')->nullable();

            // Maintained by event, so "who buys most" does not sum every order.
            $table->integer('orders_count')->default(0);
            $table->bigInteger('spent_cents')->default(0);
            $table->timestampTz('last_order_at')->nullable();

            TenantSchema::uniquePerTenant($table, ['phone_hash'], 'uq_customers_phone');
            TenantSchema::index($table, ['last_order_at'], 'idx_customers_recientes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
