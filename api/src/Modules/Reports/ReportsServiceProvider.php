<?php

declare(strict_types=1);

namespace Modules\Reports;

use Illuminate\Support\ServiceProvider;

/**
 * Reports bind no ports and listen for no events: they read what is written.
 *
 * It exists so adding a module stays "a directory, a provider and one line".
 */
final class ReportsServiceProvider extends ServiceProvider {}
