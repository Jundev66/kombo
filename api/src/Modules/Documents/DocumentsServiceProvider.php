<?php

declare(strict_types=1);

namespace Modules\Documents;

use Illuminate\Support\ServiceProvider;
use Modules\Documents\Domain\Ports\FiscalDocument;
use Modules\Documents\Infrastructure\Services\NoFiscalDocument;

/**
 * Binds the fiscal port to the implementation that issues nothing.
 *
 * One line, and it is the whole door left open: the day a tenant is certified
 * with SENIAT, another adapter is written and this line changes.
 */
final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FiscalDocument::class, NoFiscalDocument::class);
    }
}
