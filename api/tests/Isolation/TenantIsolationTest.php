<?php

declare(strict_types=1);

/*
 * Las pruebas más importantes del sistema.
 *
 * La pregunta que responden NO es «¿está aislado?». Es:
 *
 *      ¿QUÉ TIENE QUE FALLAR PARA QUE SE FILTRE UN DATO DE OTRO NEGOCIO?
 *
 * Por eso varias desactivan a propósito una capa de defensa y comprueban que
 * la siguiente aguanta sola. Un aislamiento que sólo funciona cuando todo está
 * bien no es aislamiento: es suerte.
 *
 * Corren como `kombo_app`, el usuario SIN BYPASSRLS. Si corrieran como el
 * dueño del esquema pasarían en verde con RLS completamente roto — y ése es el
 * peor fallo que puede tener una suite: silencioso, y comprobando algo
 * distinto de lo que dice comprobar.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // Sufijo aleatorio: la siembra es aditiva y estas pruebas no pueden
    // depender de que la base esté recién creada.
    $sufijo = Str::lower(Str::random(6));

    $this->arepera = makeTenant("elsazon-{$sufijo}");
    $this->pizzeria = makeTenant("laesquina-{$sufijo}");

    actingForTenant($this->arepera);
    $this->usuarioArepera = makeUser($this->arepera, "maria-{$sufijo}@ejemplo.com");

    actingForTenant($this->pizzeria);
    $this->usuarioPizzeria = makeUser($this->pizzeria, "pedro-{$sufijo}@ejemplo.com");
});

it('cada negocio ve sólo sus propios datos', function (): void {
    actingForTenant($this->arepera);
    expect(DB::table('users')->pluck('id')->all())->toBe([$this->usuarioArepera]);

    actingForTenant($this->pizzeria);
    expect(DB::table('users')->pluck('id')->all())->toBe([$this->usuarioPizzeria]);
});

it('no encuentra un registro ajeno ni pidiéndolo por su identificador', function (): void {
    // Es el caso realista: alguien copia un id de una URL y lo prueba en otra
    // cuenta. Tiene que responder «no existe», no «no puedes» — un 403 ya
    // confirmaría que el recurso existe.
    actingForTenant($this->arepera);

    expect(DB::table('users')->find($this->usuarioPizzeria))->toBeNull();
});

it('una consulta directa a la base tampoco ve lo ajeno', function (): void {
    // Sin Eloquent, sin ámbito global, sin modelo. Aquí quien filtra es
    // PostgreSQL, no el framework.
    actingForTenant($this->arepera);

    $emails = DB::select('select email from users');

    expect($emails)->toHaveCount(1);
});

it('una petición SIN negocio no devuelve NINGUNA fila, no todas', function (): void {
    // La diferencia entre estas dos respuestas es la diferencia entre un
    // sistema que falla y un sistema que filtra los datos de todos sus
    // clientes. El modo de fallo tiene que ser NEGAR.
    withoutTenant();

    expect(DB::table('users')->count())->toBe(0);
});

it('la base rechaza escribir una fila a nombre de otro negocio', function (): void {
    // Ésta es la prueba de WITH CHECK, y va con SQL crudo a propósito: por
    // Eloquent nunca llegaría aquí, porque el trait rellena el negocio solo.
    // Lo que se comprueba es qué pasa si ese trait falla o alguien lo evita.
    actingForTenant($this->arepera);

    expect(fn () => DB::table('users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->pizzeria,   // ← el negocio de OTRO
        'name' => 'Colada',
        'email' => 'colada@ejemplo.com',
        'password' => 'x',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('no se puede robar una fila ajena cambiándole el negocio', function (): void {
    // El otro lado de WITH CHECK: mover una fila propia al negocio de otro.
    actingForTenant($this->arepera);

    expect(fn () => DB::table('users')
        ->where('id', $this->usuarioArepera)
        ->update(['tenant_id' => $this->pizzeria]))
        ->toThrow(QueryException::class);
});

it('borrar lo de otro negocio no borra nada', function (): void {
    // No lanza error —sencillamente no ve la fila— y eso está bien: el
    // atacante no aprende siquiera que existe.
    actingForTenant($this->arepera);

    $borradas = DB::table('users')->where('id', $this->usuarioPizzeria)->delete();

    expect($borradas)->toBe(0);

    actingForTenant($this->pizzeria);
    expect(DB::table('users')->find($this->usuarioPizzeria))->not->toBeNull();
});

it('el aislamiento aguanta aunque el contexto quede en cadena vacía', function (): void {
    // Es como queda una conexión tras limpiarla antes de devolverla al pool.
    // `current_setting` devolvería '' y sin el nullif() el casteo a uuid
    // reventaría con un error de SQL en vez de devolver cero filas.
    DB::statement("select set_config('app.tenant_id', '', false)");

    expect(DB::table('users')->count())->toBe(0);
});
