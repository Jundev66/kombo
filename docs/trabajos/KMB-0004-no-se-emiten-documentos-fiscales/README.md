---
codigo: KMB-0004
titulo: El sistema no emite documentos fiscales
tipo: decision
estado: hecho
fecha: 2026-08-26
toca: [api/src/Modules/Documents, api/src/Modules/Counter]
relacionados: []
---

# KMB-0004 · El sistema no emite documentos fiscales

## Por qué

Un sistema de punto de venta que imprime papel entra, sin quererlo, en terreno
regulado. Y la diferencia entre «un comprobante de lo que te llevaste» y «una
factura» no es de formato: es quién responde ante la autoridad tributaria si el
número está mal.

Meterse ahí sin homologación es prometerle a un negocio algo que no se le puede
cumplir. Y en cuanto un cliente lo crea, el problema es suyo, no nuestro.

## Qué se hizo

La caja emite **notas de entrega**:

- Con su correlativo propio (`A-000001`), que es del sistema y de nadie más.
- Con **`No es una factura`** impreso en el papel, en el documento, guardado
  dentro del propio registro y no compuesto al imprimir.
- No calcula IVA como débito fiscal, no lleva libro de ventas y no numera con
  rangos de la autoridad.

Hay un puerto `FiscalDocument` con **implementación nula** por si algún día un
negocio se homologa. Mientras no exista ese adaptador, **no hay opción escondida
que convierta una nota en factura**.

Una nota por pedido, y no se reemite. De ahí dos reglas que parecen arbitrarias
y no lo son:

- **Anular el papel es anular la venta entera** (`counter.void`). No hay un
  `notes.void` aparte, porque una nota sin venta detrás no significa nada.
- **Anular no libera el número.** Un correlativo que se reutiliza deja de
  identificar nada, que es lo único que un correlativo hace.

## Qué se descartó, y por qué

**Un ajuste «modo factura».** Es lo que pediría el primer cliente que lo
necesite. Se descartó justamente por eso: un interruptor que hace que el sistema
emita algo que no está homologado es peor que no tenerlo, porque parece
soportado.

**Integrar una imprenta fiscal desde el principio.** Es trabajo real —cada
modelo con su protocolo— para un cliente que todavía no existe. El puerto queda
declarado para que el día que aparezca no haya que reescribir la caja.

## Qué falló por el camino

Nada roto, pero sí una tentación recurrente: cada vez que se toca la nota de
entrega aparece la pregunta de si añadir el desglose de IVA «sólo informativo».
La respuesta es no, y por eso está escrita aquí: un desglose informativo en un
papel que dice no ser una factura es exactamente la ambigüedad que esto viene a
evitar.

## Cómo se verificó

```bash
make test   # que la nota lleva las dos frases, y que vienen del servidor
make e2e    # caja.spec.ts: el papel dice NOTA DE ENTREGA y No es una factura
```

Las dos frases se afirman en la prueba de navegador porque son la parte del
producto que protege al negocio, y una regresión silenciosa ahí no la nota nadie
hasta que alguien la usa como factura.

## Lo que quedó fuera

El adaptador fiscal real. Cuando exista, va contra el puerto `FiscalDocument` y
se enciende como un módulo más ([KMB-0002]) — no como un ajuste de la caja.
