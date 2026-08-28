<?php

declare(strict_types=1);

/*
 * La regla de oro: NUNCA se escribe código para un solo negocio.
 *
 * No es una postura estética. Es la única manera de que una persona atienda a
 * cien clientes sin quebrarse, y es literalmente el problema que este sistema
 * viene a resolver. Ante «el cliente X necesita…», hay cuatro escalones y se
 * para en el primero que resuelva:
 *
 *   1. ¿Ya es configurable?          Nada que tocar. Se enseña dónde.
 *   2. ¿Puede ser una opción?        Un `Setting` en el manifiesto, con el
 *                                    valor por defecto IGUAL al comportamiento
 *                                    de hoy. Todos la reciben, nadie lo nota.
 *   3. ¿Puede ser un interruptor?    Un `Setting` booleano, false por defecto.
 *   4. ¿Puede ser un módulo?         Manifiesto, tablas, casos de uso, rutas.
 *                                    Se ofrece a todo el que lo necesite.
 *
 * Si no cabe en ninguno, la respuesta es NO — y se dice, con la razón por
 * delante. Nunca es «un programador le hace un ajuste a este cliente».
 * Esta prueba es lo que impide que esa opción exista.
 */

use Tests\Support\SourceScanner;

it('no compara contra el identificador de un negocio concreto', function (): void {
    $offenders = [];

    foreach (SourceScanner::files() as $file) {
        $contents = (string) file_get_contents($file);

        // Un UUID literal comparado contra algo que se llame tenant.
        if (preg_match('/tenant(_?id)?\s*(===?|!==?|,)\s*[\'"][0-9a-f]{8}-[0-9a-f]{4}-/i', $contents)) {
            $offenders[] = SourceScanner::relative($file).' compara contra un UUID de negocio literal';
        }

        // where('slug', 'elsazon') y parientes.
        if (preg_match('/->where\(\s*[\'"](slug|subdomain)[\'"]\s*,\s*[\'"][a-z0-9-]+[\'"]/i', $contents)) {
            $offenders[] = SourceScanner::relative($file).' busca un negocio por su slug literal';
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Hay código escrito para UN negocio concreto:',
        ...$offenders,
        '',
        'Sube por los cuatro escalones: ¿ya es configurable? ¿puede ser una',
        'opción? ¿un interruptor? ¿un módulo? Si no cabe en ninguno, la',
        'respuesta es no.',
    ]));
});

it('ningún modelo deja tenant_id asignable en masa', function (): void {
    // `Model::create($request->all())` con `tenant_id` asignable es una
    // petición HTTP escribiendo una fila a nombre de otro negocio. La política
    // WITH CHECK de RLS lo rechazaría igual, pero el error saldría como un 500
    // críptico en vez de sencillamente no existir.
    //
    // Se aceptan las dos formas de declararlo: el atributo #[Fillable([...])]
    // de Laravel 13 y la propiedad $fillable de siempre. Lo que NO se acepta
    // es que un modelo no diga nada — el defecto silencioso es el peligroso.
    $offenders = [];

    foreach (SourceScanner::files(['app/Models']) as $file) {
        $contents = (string) file_get_contents($file);
        $name = SourceScanner::relative($file);

        /*
         * Los modelos de PLATAFORMA quedan fuera, y no es un agujero.
         *
         * La regla existe para los modelos de negocio: ahí `tenant_id` lo pone
         * `BelongsToTenant` desde el contexto, y dejarlo asignable sería que
         * una petición HTTP escribiera a nombre de otro negocio.
         *
         * `subscriptions` y `subscription_payments` son otra cosa: son tablas
         * globales, sin RLS, y quien decide de qué negocio es una suscripción
         * es la plataforma — no hay contexto del que sacarlo. Prohibirlo aquí
         * obligaría a escribir la columna a mano después de crear la fila, que
         * es más código para el mismo resultado y una fila menos válida por el
         * camino.
         *
         * Se distingue por el trait, no por una lista de nombres: un modelo de
         * negocio nuevo entra en la regla sin que nadie se acuerde de añadirlo.
         */
        if (! str_contains($contents, 'BelongsToTenant')) {
            continue;
        }

        $declaresProperty = (bool) preg_match('/\$fillable\s*=\s*\[/', $contents);
        $declaresAttribute = (bool) preg_match('/#\[Fillable\(/', $contents);

        if (! $declaresProperty && ! $declaresAttribute) {
            $offenders[] = "{$name} no declara qué es asignable (#[Fillable] o \$fillable)";

            continue;
        }

        if (preg_match('/(#\[Fillable\(|\$fillable\s*=\s*)\[[^\]]*[\'"]tenant_id[\'"]/s', $contents)) {
            $offenders[] = "{$name} deja tenant_id asignable";
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Un modelo deja tenant_id al alcance de la asignación en masa:',
        ...$offenders,
        '',
        'El trait BelongsToTenant lo rellena solo al crear. No hace falta que',
        'sea asignable, y que lo sea es un agujero.',
    ]));
});

it('el código de negocio no se salta el filtro por negocio', function (): void {
    // `acrossTenants()` existe —lo necesitan el panel de plataforma y las
    // propias pruebas de aislamiento— pero dentro de un módulo no tiene
    // ninguna razón legítima de ser.
    $offenders = [];

    foreach (SourceScanner::files(['src/Modules']) as $file) {
        if (preg_match('/acrossTenants\(|withoutGlobalScope\(\s*TenantScope/', (string) file_get_contents($file))) {
            $offenders[] = SourceScanner::relative($file);
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Un módulo se salta el filtro por negocio:',
        ...$offenders,
        '',
        'Eso sólo lo puede hacer la plataforma (el panel interno) y las',
        'pruebas de aislamiento. Desde un módulo, nunca.',
    ]));
});
