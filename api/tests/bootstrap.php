<?php

declare(strict_types=1);

/*
 * Pins the test environment BEFORE anything boots.
 *
 * `compose.yml` sets `DB_DATABASE=kombo` in the container, and Laravel's `.env`
 * loader is immutable — it does not override what is already in the process
 * environment. Without this, the suite would happily run against the
 * DEVELOPMENT database.
 *
 * And above all: DB_USERNAME is `kombo_app`, the user WITHOUT BYPASSRLS.
 *
 * Running the tests as the superuser `kombo_owner` would make the isolation
 * ones pass green with Row Level Security completely broken, because BYPASSRLS
 * skips every policy silently. The worst kind of failure a suite can have.
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
