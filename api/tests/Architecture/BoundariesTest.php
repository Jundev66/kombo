<?php

declare(strict_types=1);

/*
 * Los límites del diseño, y la prueba que rompe cada uno si lo violas.
 *
 * Cuando una de estas falla, la respuesta casi nunca es cambiar la prueba.
 */

use Tests\Support\SourceScanner;

it('la plataforma no depende de ningún módulo', function (): void {
    // El corolario que hace posible el producto: agregar un módulo —o
    // borrarlo— no toca el motor. Si esto falla, el motor dejó de ser un motor
    // y pasó a ser parte de la aplicación.
    expect('Platform')->not->toUse('Modules');
});

it('el núcleo compartido no depende de nadie', function (): void {
    // Money, ExchangeRate y compañía los usa todo el mundo. En cuanto miren
    // hacia arriba, dejan de poder usarse desde donde haga falta.
    expect('Shared')
        ->not->toUse('Platform')
        ->not->toUse('Modules')
        ->not->toUse('App');
});

it('ninguna capa de dominio importa el framework', function (): void {
    // Escrita recorriendo ficheros y NO con una lista de excepciones a
    // propósito: la versión con lista obliga a añadir cada módulo nuevo, y el
    // día que alguien lo olvide la prueba deja de vigilar sin que nadie se
    // entere. Esta se entera sola.
    $offenders = [];

    foreach (SourceScanner::files() as $file) {
        $contents = file_get_contents($file);

        if (! preg_match('/^namespace .*\\\\Domain(\\\\|;)/m', $contents)) {
            continue;
        }

        if (preg_match_all('/^use (Illuminate\\\\[^;]+);/m', $contents, $matches)) {
            foreach ($matches[1] as $import) {
                $offenders[] = SourceScanner::relative($file).' importa '.$import;
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Una capa de dominio importó el framework:',
        ...$offenders,
        '',
        'El dominio son las reglas del negocio en PHP puro. Si necesitas la',
        'base de datos o una petición HTTP, eso va en Application o en',
        'Infrastructure, detrás de un puerto que el dominio declare.',
    ]));
});

it('todo el código propio declara tipos estrictos', function (): void {
    $offenders = [];

    foreach (SourceScanner::files() as $file) {
        if (! str_contains((string) file_get_contents($file), 'declare(strict_types=1)')) {
            $offenders[] = SourceScanner::relative($file);
        }
    }

    expect($offenders)->toBe([], "Falta declare(strict_types=1) en:\n".implode("\n", $offenders));
});

it('no queda código de depuración', function (): void {
    // `dd()` en producción no es un detalle de estilo: corta la respuesta a la
    // mitad y vuelca el estado interno al navegador de quien esté delante.
    $forbidden = ['dd(', 'dump(', 'ray(', 'var_dump(', 'die(', 'exit('];
    $offenders = [];

    foreach (SourceScanner::files() as $file) {
        $contents = (string) file_get_contents($file);

        foreach ($forbidden as $needle) {
            if (preg_match('/(?<![\w>$])'.preg_quote($needle, '/').'/', $contents)) {
                $offenders[] = SourceScanner::relative($file).' usa '.rtrim($needle, '(');
            }
        }
    }

    expect($offenders)->toBe([], "Código de depuración olvidado:\n".implode("\n", $offenders));
});

it('ningún módulo importa entidades de otro módulo', function (): void {
    // Dos módulos se hablan de dos maneras, y sólo dos:
    //
    //   ¿Necesitas saber algo AHORA?      Un puerto en Application\Contracts
    //                                     del que lo publica, y un DTO.
    //   ¿Reaccionas a algo QUE YA PASÓ?   Un evento de dominio.
    //
    // Importar la entidad de otro módulo es poder invocar sus reglas desde
    // fuera del módulo que las defiende. De ahí no se vuelve.
    $offenders = [];

    foreach (SourceScanner::files() as $file) {
        $contents = (string) file_get_contents($file);

        if (! preg_match('/^namespace Modules\\\\((?:Vertical\\\\)?\w+)/m', $contents, $own)) {
            continue;
        }

        if (! preg_match_all('/^use Modules\\\\((?:Vertical\\\\)?\w+)\\\\Domain\\\\Entities\\\\([^;]+);/m', $contents, $matches, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($matches as $match) {
            if ($match[1] !== $own[1]) {
                $offenders[] = SourceScanner::relative($file)." importa {$match[1]}\\Domain\\Entities\\{$match[2]}";
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Un módulo importó la entidad de otro:',
        ...$offenders,
        '',
        'Usa un puerto y un DTO, o un evento si es algo que ya ocurrió.',
    ]));
});
