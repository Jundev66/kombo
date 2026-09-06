---
codigo: KMB-0010
titulo: Iconos propios, menú agrupado y enlaces entre pantallas
tipo: funcionalidad
estado: hecho
fecha: 2026-08-29
toca: [web/packages/ui/src/components/icons.tsx, web/packages/shell, web/apps/dashboard/src/modules/registry.tsx]
relacionados: [KMB-0003, KMB-0005]
---

# KMB-0010 · Iconos propios, menú agrupado y enlaces entre pantallas

## Por qué

Tres cosas del armazón, todas con el mismo síntoma: el panel se veía a medio
terminar aunque funcionara.

**El menú eran doce entradas planas.** «Categorías» y «Agregados» al mismo nivel
que «Equipo», y en el teléfono sólo cabían tres: las otras nueve —Horario,
Equipo y Tasa entre ellas— quedaban enterradas en «⋯ Más». Y había **dos**
navegaciones distintas: en escritorio una fila plana con las doce, en móvil tres
y un «Más». Dos menús para el mismo sistema significa que uno está mal; aquí lo
estaban los dos.

**Los iconos eran emojis.** Cada sistema operativo dibuja el suyo, así que la
misma pantalla se veía distinta en la tablet de la cocina, en el teléfono del
dueño y en la PC del mostrador — y en un Android viejo alguno salía como un
cuadrito. Un icono que a veces es un cuadrito no es un icono.

**Las cuatro pantallas eran islas.** Para pasar del panel a la caja había que
saberse la URL de memoria.

## Qué se hizo

**`packages/ui/src/icons.tsx`**: quince trazos SVG en línea, exportados uno a
uno para que la aplicación que no los usa no los arrastre. Menos de un kilobyte
entre todos.

**Menú agrupado y una sola navegación** para los dos tamaños: tres primarias en
la barra —Pedidos, Carta, Ventas— y el resto en un panel con encabezados
(Pantallas del local · Carta · Reparto y clientes · Negocio).

**`href` en `ModuleUi`**: una entrada puede llevar a otra de las cinco
aplicaciones. Así el panel enlaza la caja y la cocina ([KMB-0005]), filtradas
con la misma regla que todo lo demás —módulo encendido Y permiso— así que un
negocio sin mostrador no ve el enlace.

Y la cabecera dice **la persona y su rol**: sin el rol, «esto no se puede» y
«esto no lo puedes tú» se ven igual y son cosas muy distintas de resolver.

## Qué se descartó, y por qué

**Una librería de iconos.** El presupuesto de arranque son 180 KB en la caja y
120 KB en la cocina, y cualquier paquete se lleva una parte que no vuelve.
Quince iconos dibujados a mano cuestan menos que la infraestructura para
importar quince de un paquete de mil.

**Dejar que un enlace externo ocupe una pestaña de la barra.** Se probó y se
quitó: una pestaña promete volver —se toca, cambia el contenido, y la barra
sigue ahí—. La caja y la cocina son otra aplicación y el botón de atrás no
devuelve al panel. En el panel de «Más» van marcadas con su flecha y el gesto es
deliberado.

## Qué falló por el camino

En La Esquina —que no tiene reportes— el hueco de la tercera pestaña se rellenó
con **«Cocina»**, un enlace externo, porque `splitMenu` rellena huecos con lo
que haya. Se vio en el navegador, no leyendo el código. Ahora los `href` no son
candidatos a rellenar la barra salvo que alguien les ponga `primary` a mano.

Y el `h1` de la página pasó a ser el nombre del negocio, lo que rompió una E2E
que leía el número de pedido del encabezado. El arreglo no fue la prueba: en el
seguimiento el encabezado **debe** ser el pedido, que es el dato que el cliente
lee en voz alta cuando llama.

## Cómo se verificó

```bash
make check     # el presupuesto: panel 104 KB de 220
./e2e/run.sh
```

Y a ojo en escritorio y en teléfono, con el dueño y con el encargado, y en el
negocio sin caja para comprobar que el enlace no aparece.

## Lo que quedó fuera

Las nueve pantallas restantes del panel. Se miraron renderizadas y siguen los
principios, así que no se tocaron: cambiar lo que funciona para que se noten los
cambios es cómo se rompe lo que funcionaba.
