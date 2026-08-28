<?php

declare(strict_types=1);

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Recorre el código propio del proyecto —nunca `vendor/`—.
 *
 * Existe porque varias pruebas de arquitectura no se pueden expresar con las
 * expectativas de Pest: preguntan por el CONTENIDO de los ficheros (¿declara
 * tipos estrictos? ¿importa el framework desde una capa de dominio?), no por
 * las dependencias entre clases ya cargadas.
 */
final class SourceScanner
{
    /**
     * @return list<string>
     */
    public static function files(array $roots = ['src', 'app']): array
    {
        $base = dirname(__DIR__, 2);
        $found = [];

        foreach ($roots as $root) {
            $path = $base.'/'.$root;

            if (! is_dir($path)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $found[] = $file->getPathname();
                }
            }
        }

        sort($found);

        return $found;
    }

    public static function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 2).'/', '', $path);
    }
}
