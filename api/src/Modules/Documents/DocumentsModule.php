<?php

declare(strict_types=1);

namespace Modules\Documents;

use Platform\Modules\ModuleManifest;
use Platform\Modules\Setting;

/**
 * Los documentos que se entregan al cliente.
 *
 * Hoy sólo uno: la **nota de entrega**. Es un documento comercial, no fiscal —
 * el papel lo dice— y no sustituye a la factura ni elimina las obligaciones
 * tributarias del negocio.
 */
final class DocumentsModule extends ModuleManifest
{
    public function code(): string
    {
        return 'documents';
    }

    public function name(): string
    {
        return 'Notas de entrega';
    }

    public function description(): string
    {
        return 'El papel que se le entrega al cliente con lo que se llevó y cuánto pagó.';
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return ['orders'];
    }

    public function routes(): ?string
    {
        return __DIR__.'/Interfaces/Http/Routes/api.php';
    }

    public function migrations(): ?string
    {
        return __DIR__.'/Infrastructure/Migrations';
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return [
            'notes.issue',
            'notes.reprint',

            // No hay `notes.void`: como sólo puede haber una nota por pedido y
            // no se reemite, anular el papel es anular la venta entera. Ese
            // permiso vive en la caja (`counter.void`), que es donde se hace.
        ];
    }

    /**
     * @return array<string, Setting>
     */
    public function settings(): array
    {
        return [
            // Lo que va debajo del total: horario, dirección, «gracias por su
            // compra». Lo escribe el dueño una vez.
            'footer_text' => Setting::text('')->maxLength(300),

            // Si los precios de la carta ya llevan impuesto incluido. Aquí sólo
            // afecta a cómo se muestra el total en el papel: este sistema NO
            // calcula IVA como débito fiscal.
            'prices_include_tax' => Setting::bool(true),
        ];
    }
}
