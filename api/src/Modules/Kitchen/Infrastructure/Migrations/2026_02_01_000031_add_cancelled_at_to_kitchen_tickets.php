<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ticket can also end up cancelled — the customer changes their mind, or the
 * till voids the sale with the arepa already on the griddle.
 *
 * It is not deleted (stock was involved) but comes off the board, and like
 * every other step it stamps its time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kitchen_tickets', function (Blueprint $table): void {
            $table->timestampTz('cancelled_at')->nullable()->after('served_at');
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_tickets', function (Blueprint $table): void {
            $table->dropColumn('cancelled_at');
        });
    }
};
