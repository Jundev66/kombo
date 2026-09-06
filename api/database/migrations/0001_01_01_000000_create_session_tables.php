<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions and password recovery.
 *
 * PLATFORM tables (declared in TenantSchema::PLATFORM_TABLES), hence no
 * `tenant_id` and no RLS: the session is resolved before we know the tenant.
 *
 * `users` is not here: it is a tenant table, created with TenantSchema.
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

        // Worth remembering when password recovery gets built: the key is the
        // email, and the same email can exist in two tenants. The tenant will have
        // to go into the key, or be resolved from the subdomain.
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
