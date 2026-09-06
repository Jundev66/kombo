<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Access tokens, our own version and a TENANT table.
 *
 * Sanctum's has no `tenant_id`, so a valid token would work on any subdomain.
 *
 * One declared exception: the unique index on the token hash is GLOBAL. It has
 * to be — a token must resolve to a single user BEFORE we know its tenant. It
 * sits in TenantSchema::GLOBAL_UNIQUE_INDEXES with its reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('personal_access_tokens', function (Blueprint $table): void {
            // Declared by hand rather than with `uuidMorphs()`, so TenantSchema puts
            // the index in place with `tenant_id` first.
            $table->uuid('tokenable_id');
            $table->string('tokenable_type');

            $table->text('name');
            $table->string('token', 64)->unique();

            // 'device'  → only list the names and validate a PIN.
            // 'station' → operate, in the name of whoever entered their PIN.
            $table->text('abilities')->nullable();

            $table->string('device_id')->nullable();
            $table->timestampTz('last_used_at')->nullable();

            // No clock expiry: a till can go a day untouched and cannot be locked out
            // mid-sale. Revoked explicitly, which beats a surprise sign-out at seven in
            // the evening.
            $table->timestampTz('expires_at')->nullable();

            TenantSchema::index($table, ['tokenable_type', 'tokenable_id'], 'idx_pat_tenant_tokenable');
            TenantSchema::index($table, ['expires_at'], 'idx_pat_tenant_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
