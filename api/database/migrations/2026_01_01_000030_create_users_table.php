<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * The users. A TENANT table, not a platform one.
 *
 * A user belongs to ONE tenant and the email is unique within it, so the same
 * person can exist in two without colliding and signing in never has to ask
 * which.
 *
 * Created with TenantSchema, so `tenant_id`, forced RLS, the isolation policy
 * and the unique `(tenant_id, id)` all arrive without anyone remembering.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('users', function (Blueprint $table): void {
            $table->string('name');
            $table->string('email');
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');

            // Operating PIN, hashed. The till and the kitchen are shared machines on
            // the floor: nobody types an email and a long password with their hands
            // full and a customer waiting.
            // It also authorises sensitive actions in the name of whoever authorises
            // them, without signing out whoever started them.
            $table->string('pin_hash')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();

            TenantSchema::uniquePerTenant($table, ['email'], 'uq_users_tenant_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
