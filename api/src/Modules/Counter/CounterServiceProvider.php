<?php

declare(strict_types=1);

namespace Modules\Counter;

use Illuminate\Support\ServiceProvider;

/**
 * The till binds no port of its own; it orchestrates `Orders` and `Documents`.
 *
 * It exists so that adding a module stays "a directory, a provider and one
 * line", with no exceptions to remember.
 */
final class CounterServiceProvider extends ServiceProvider {}
