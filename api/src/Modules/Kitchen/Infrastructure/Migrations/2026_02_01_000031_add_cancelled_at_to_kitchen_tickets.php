<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una comanda también puede terminar cancelada.
 *
 * Pasa a diario: el cliente se arrepiente, o en la caja anulan la venta con la
 * arepa ya en la plancha. La comanda no se borra —hubo materia prima de por
 * medio y el dueño va a querer saber cuánta se perdió— pero sale del tablero,
 * y como todos los demás pasos, éste sella su hora.
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
