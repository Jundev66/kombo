<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenants. A PLATFORM table, without RLS for a chicken-and-egg reason: it
 * is queried to FIND OUT which tenant we are talking about.
 *
 * The one table everything else hangs off, and signing up a customer is exactly
 * one row here: no DNS, no certificate, no deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The subdomain. Unique system-wide, lower-cased, with a reserved list in
            // the resolver (`admin`, `www`, `api`…) so nobody registers a tenant that
            // hijacks a platform address.
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

            // 90 days after payment stops. The tenant goes read-only and can export
            // everything first: deleting the data of someone who trusted the system,
            // without warning, is not an option.
            $table->timestampTz('data_expires_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
            $table->foreign('plan_code')->references('code')->on('plans')->restrictOnDelete();
        });

        // Enforced with a CHECK rather than a PostgreSQL ENUM: adding a value to
        // an ENUM locks the table, and these statuses are going to grow.
        DB::statement("alter table tenants add constraint chk_tenants_status
            check (status in ('trial','active','past_due','suspended','closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
