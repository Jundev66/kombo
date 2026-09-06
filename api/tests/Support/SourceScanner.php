<?php

declare(strict_types=1);

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Walks the project's own code, never `vendor/`.
 *
 * It exists because several architecture tests cannot be expressed with Pest's
 * expectations: they ask about the CONTENT of files (does it declare strict
 * types? does it import the framework from a domain layer?) rather than about
 * dependencies between already-loaded classes.
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
