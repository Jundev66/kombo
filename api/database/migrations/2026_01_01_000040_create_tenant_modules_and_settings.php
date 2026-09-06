<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * Which modules a tenant has switched on, and how it wants them.
 *
 * These two tables make turning on the till or the channels one row with
 * nothing to deploy. The plan sets the ceiling; these say what is on within it.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('tenant_modules', function (Blueprint $table): void {
            $table->string('module_code');
            $table->boolean('enabled')->default(true);
            $table->uuid('enabled_by')->nullable();
            $table->timestampTz('enabled_at')->nullable();

            TenantSchema::uniquePerTenant($table, ['module_code'], 'uq_tenant_modules_code');
        });

        TenantSchema::create('tenant_settings', function (Blueprint $table): void {
            // Qualified key: `orders.auto_confirm`, `kitchen.stale_minutes`.
            $table->string('key');

            // ALWAYS text. The type is declared by the module manifest and cast by
            // `Setting::cast()`; storing it here too would mean two places to disagree.
            $table->text('value');

            $table->uuid('updated_by')->nullable();

            TenantSchema::uniquePerTenant($table, ['key'], 'uq_tenant_settings_key');
        });

        // DEFAULTS are not stored here: they live in the manifest's `settings()`,
        // so adding an option touches no rows and changing a default for everybody
        //
        // is one line of code rather than a data migration.
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('tenant_modules');
    }
};
