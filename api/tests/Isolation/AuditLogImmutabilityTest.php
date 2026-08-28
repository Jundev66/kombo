<?php

declare(strict_types=1);

/*
 * La bitácora no se puede editar. Y no porque nadie escriba el código para
 * hacerlo, sino porque el usuario con el que la aplicación conecta a PostgreSQL
 * tiene INSERT y SELECT sobre esta tabla, y nada más.
 *
 * Es la única parte del sistema donde un privilegio de la base hace un trabajo
 * que el código no puede hacer solo: mientras esté ahí, ni un error, ni una
 * migración descuidada, ni alguien con acceso a la aplicación puede reescribir
 * el histórico. Y es la segunda razón por la que hay dos usuarios de base de
 * datos.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->tenant = makeTenant('bitacora-'.Str::lower(Str::random(6)));
    actingForTenant($this->tenant);

    $this->entryId = (string) Str::uuid7();

    DB::table('audit_log')->insert([
        'id' => $this->entryId,
        'tenant_id' => $this->tenant,
        'user_name' => 'Ana',
        'action' => 'orders.void',
        'reason' => 'El cliente se arrepintió',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('se puede insertar y leer', function (): void {
    $entrada = DB::table('audit_log')->find($this->entryId);

    expect($entrada?->action)->toBe('orders.void')
        ->and($entrada?->user_name)->toBe('Ana');
});

it('NO se puede modificar, aunque el código lo intente', function (): void {
    // El caso que esto impide es concreto: alguien con acceso a la aplicación
    // cambiando el motivo de una anulación después de que se pregunte por ella.
    expect(fn () => DB::table('audit_log')
        ->where('id', $this->entryId)
        ->update(['reason' => 'Otra cosa']))
        ->toThrow(QueryException::class);
});

it('NO se puede borrar, aunque el código lo intente', function (): void {
    expect(fn () => DB::table('audit_log')->where('id', $this->entryId)->delete())
        ->toThrow(QueryException::class);
});

it('y sigue sin poderse borrar en masa', function (): void {
    expect(fn () => DB::table('audit_log')->delete())
        ->toThrow(QueryException::class);
});
