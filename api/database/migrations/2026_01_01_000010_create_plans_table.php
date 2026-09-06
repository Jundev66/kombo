<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plans. A PLATFORM table: no `tenant_id` and no RLS.
 *
 * The business rule is in which columns exist and which do not: you pay for
 * SIZE, not for basic functionality — no tenant is left unable to see what it
 * sold. Hence no `has_reports` flags; the modules a plan includes live in
 * `plan_modules`, because there what you buy is a new capability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);

            $table->bigInteger('price_cents')->default(0);
            $table->char('currency', 3)->default('USD');

            // NULL = unlimited. Never zero: zero means "none", a different answer and
            // far worse to debug.
            $table->integer('max_users')->nullable();
            $table->integer('max_products')->nullable();
            $table->integer('max_orders_month')->nullable();

            $table->integer('trial_days')->nullable();
            $table->boolean('is_public')->default(true);

            $table->timestampsTz();
        });

        Schema::create('plan_modules', function (Blueprint $table): void {
            $table->string('plan_code');
            $table->string('module_code');

            $table->primary(['plan_code', 'module_code']);
            $table->foreign('plan_code')->references('code')->on('plans')->cascadeOnDelete();
        });

        // `module_code` is free text rather than a foreign key: there is no
        // `modules` table. What exists is stated by the code, not by a row someone
        // could delete.
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_modules');
        Schema::dropIfExists('plans');
    }
};
