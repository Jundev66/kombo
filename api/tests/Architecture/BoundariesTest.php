<?php

declare(strict_types=1);

/*
 * The design boundaries, and the test that breaks on each one.
 *
 * When one of these fails, the answer is almost never to change the test.
 */

use Tests\Support\SourceScanner;

it('the platform depends on no module', function (): void {
    // The corollary that makes the product possible: adding or deleting a
    // module does not touch the engine. If this fails, the engine has become
    // part of the application.
    expect('Platform')->not->toUse('Modules');
});

it('the shared core depends on nobody', function (): void {
    // Money, ExchangeRate and company are used by everyone. The moment they
    // look upwards they stop being usable from wherever they are needed.
    expect('Shared')
        ->not->toUse('Platform')
        ->not->toUse('Modules')
        ->not->toUse('App');
});

it('no domain layer imports the framework', function (): void {
    // Written by walking files rather than from a list of exceptions: a list
    // has to grow with every new module, and the day somebody forgets, the
    // test stops watching without anyone noticing. This one notices itself.
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

it('all our own code declares strict types', function (): void {
    $offenders = [];

    foreach (SourceScanner::files() as $file) {
        if (! str_contains((string) file_get_contents($file), 'declare(strict_types=1)')) {
            $offenders[] = SourceScanner::relative($file);
        }
    }

    expect($offenders)->toBe([], "Falta declare(strict_types=1) en:\n".implode("\n", $offenders));
});

it('no debugging code is left behind', function (): void {
    // `dd()` in production is not a style detail: it cuts the response in half
    // and dumps internal state into the browser of whoever is in front of it.
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

it('no module imports another module\'s entities', function (): void {
    // Two modules talk in two ways, and only two:
    //   Need to know something NOW?     A port in the publisher's
    //                                   Application\Contracts, plus a DTO.
    //   Reacting to what already happened?   A domain event.
    // Importing another module's entity means invoking its rules from outside
    // the module that defends them. There is no coming back from that.
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
