<?php

declare(strict_types=1);

namespace Modules\Documents;

use Illuminate\Support\ServiceProvider;
use Modules\Documents\Domain\Ports\FiscalDocument;
use Modules\Documents\Infrastructure\Services\NoFiscalDocument;

/**
 * Enlaza el puerto fiscal con la implementación que **no emite nada**.
 *
 * Es una sola línea, y es toda la puerta que se deja abierta: el día que un
 * negocio se homologue con el SENIAT, se escribe otro adaptador y se cambia
 * esta línea. Ni la caja ni los pedidos se enteran.
 *
 * Mientras tanto, el sistema emite notas de entrega y punto.
 */
final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FiscalDocument::class, NoFiscalDocument::class);
    }
}
