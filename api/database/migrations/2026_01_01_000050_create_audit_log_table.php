<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Tenancy\Database\TenantSchema;

/**
 * The audit log. Insert-only, and not by convention.
 *
 * Who did what, when, from where and with whose authorisation. It exists for
 * two uncomfortable conversations: "the till is short" and "I did not void
 * that order".
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::create('audit_log', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable();

            // The name COPIED, not referenced: if the user is deleted tomorrow, the log
            // still has to say who it was.
            $table->string('user_name')->nullable();

            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->uuid('entity_id')->nullable();

            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->text('reason')->nullable();

            // Who authorised with their PIN. Both fields: an id with no name cannot be
            // read, and a name with no id cannot be traced.
            $table->uuid('authorized_by')->nullable();
            $table->string('authorized_by_name')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('device_id')->nullable();

            $table->boolean('is_support_access')->default(false);
            $table->string('support_case')->nullable();

            TenantSchema::index($table, ['created_at'], 'idx_audit_tenant_created');
            TenantSchema::index($table, ['action'], 'idx_audit_tenant_action');
            TenantSchema::index($table, ['entity_type', 'entity_id'], 'idx_audit_tenant_entity');
        });

        // ── What makes the immutability REAL ─────────────────────────────────
        //
        // The application's database user can INSERT into and READ this table and
        // nothing else. Neither the code, nor a bug, nor someone with access to the
        // application can alter the history — the only place where a PostgreSQL
        //
        // privilege does a job the code cannot, and the second reason there are two
        // database users.
        DB::statement('revoke update, delete on audit_log from '.self::appUser());
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }

    private static function appUser(): string
    {
        // From the environment: in CI and production the user may be named
        // differently, and a migration assuming one fails late.
        return (string) config('database.connections.pgsql.username', 'kombo_app');
    }
};
