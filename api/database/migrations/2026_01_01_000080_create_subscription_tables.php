<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform billing itself.
 *
 * All global — no `tenant_id`, no RLS — because "is this tenant up to date?"
 * has to be answerable while looking at every tenant at once.
 *
 * One field rules the rest: `current_period_end`. No `is_paid`, no flag anyone
 * has to remember to move — a date, and a daily job that reads it. That is the
 * gap the previous project left open with a `plan_expires_at` nobody read.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The platform administrators, a table apart from `users` rather than
         * an `is_super` flag: they come in at different places and see different
         * things, and confusing them is how one customer's employee ends up
         * with access to everybody's billing.
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

            // One live subscription per tenant. The history lives in the payments.
            $table->uuid('tenant_id')->unique();
            $table->string('plan_code');

            $table->string('status')->default('trial');

            $table->timestampTz('started_at');

            /*
             * The only field that decides: how long it is paid up to. A daily
             * job warns ahead, marks it overdue once passed, and suspends once
             * the grace period runs out.
             */
            $table->timestampTz('current_period_end');

            /*
             * Days tolerated after expiry, per subscription rather than
             * hard-coded: a customer of years gets fifteen, one of a month gets
             * three. That decision belongs to whoever does the billing.
             */
            $table->integer('grace_days')->default(5);

            $table->timestampTz('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('plan_code')->references('code')->on('plans')->restrictOnDelete();

            // The daily job's query: "expiring soon or already expired", by date.
            $table->index(['current_period_end', 'status'], 'idx_subscriptions_vencimiento');
        });

        /*
         * The payments, recorded by hand: people pay by transfer and there is
         * no gateway to announce it. Pretending there is automatic collection
         * would be worse than owning it.
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

            // Which period it covers, stored as well as moving `current_period_end`: a
            // month's amount cannot be inferred from a date, since there have been
            // discounted months and double months.
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
         * The PLATFORM's audit log, separate from each tenant's. What goes here
         * is what an administrator does — signing a tenant up, suspending it,
         * looking at their data. Merging them would either leave the tenant's
         * own log without RLS, or hide from them what we did in their house.
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
