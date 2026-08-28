<?php

declare(strict_types=1);

/*
 * Fija el entorno de pruebas ANTES de que arranque nada.
 *
 * Hace falta por una razón concreta y desagradable: `compose.yml` define
 * `DB_DATABASE=kombo` en el contenedor, y el cargador de `.env` de Laravel es
 * inmutable — no pisa lo que ya viene en el entorno del proceso. Sin esto, la
 * suite correría alegremente contra la base de DESARROLLO.
 *
 * Y sobre todo: DB_USERNAME es `kombo_app`, el usuario SIN BYPASSRLS.
 *
 * Si las pruebas corrieran como `kombo_owner` —que es superusuario— las de
 * aislamiento pasarían en verde con Row Level Security completamente roto,
 * porque BYPASSRLS se salta toda política sin decir nada. Es la peor clase de
 * fallo que puede tener una suite: verde, silencioso, y comprobando algo
 * distinto de lo que dice comprobar.
 */

$overrides = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'kombo_test',
    'DB_USERNAME' => 'kombo_app',
];

foreach ($overrides as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
