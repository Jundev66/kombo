<?php

declare(strict_types=1);

namespace Modules\Documents\Domain\Ports;

/**
 * La puerta que se deja abierta por si algún día el negocio se homologa.
 *
 * **Hoy no hay ninguna implementación real, y eso es deliberado.** Este
 * sistema emite NOTAS DE ENTREGA, que son documentos comerciales, no fiscales.
 * No calcula IVA como débito fiscal, no lleva libro de ventas y no numera con
 * rangos asignados por la autoridad.
 *
 * Una nota de entrega **no sustituye a la factura** ni elimina las
 * obligaciones tributarias del negocio: emitir facturas exige los medios
 * autorizados por el SENIAT —imprenta autorizada o máquina fiscal homologada—
 * y eso es un trámite del negocio, no algo que el software pueda resolver
 * solo.
 *
 * Lo que hace este puerto es **no cerrar el camino**: si mañana un cliente se
 * homologa, se escribe un adaptador y se enchufa sin tocar `Counter` ni
 * `Orders`. Mientras no exista, la caja emite notas y punto — no hay ninguna
 * opción escondida que las convierta en facturas.
 */
interface FiscalDocument
{
    /** ¿Este negocio puede emitir documentos fiscales? */
    public function isAvailable(): bool;

    /**
     * Emite el documento fiscal de un pedido y devuelve su identificación.
     *
     * @return array{number: string, controlNumber: string}|null
     */
    public function issueFor(string $orderId): ?array;
}
