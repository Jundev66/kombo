---
codigo: KMB-0014
titulo: El panel de administración distingue servidor caído de sesión cerrada
tipo: arreglo
estado: hecho
fecha: 2026-09-06
toca: [web/apps/admin, web/packages/shell, e2e/tests/admin.spec.ts]
relacionados: [KMB-0012, KMB-0013]
---

# KMB-0014 · El panel de administración distingue servidor caído de sesión cerrada

## Por qué

`web/apps/admin/src/App.tsx` decidía qué pintar con una sola pregunta:

```tsx
if (session.data == null) {
  return <LoginScreen onDone={() => void session.refetch()} />
}
```

`/platform/me` responde **200 con `data: null`** cuando no hay sesión, así que
esa línea acierta en el caso normal. Pero cuando la petición **falla**, `data`
llega `undefined`, que también es `== null`, y la pantalla de acceso se pinta
igual. A quien mira le dice «entra» cuando su contraseña nunca fue el problema.

Se descubrió por el peor camino posible. Durante todo [[KMB-0012]] —cuando nginx
marcaba una IP muerta y la API devolvía 502 a **todo**— la prueba
`admin.spec.ts:28 platform administration does not open without signing in`
estuvo **verde**. Comprobaba que la puerta se ve sin haber entrado, y la puerta
se veía: por el fallo, no por el acierto. Sesenta y ocho pruebas en rojo y ésa
en verde, dando falsa confianza sobre justo la parte que estaba rota.

`packages/shell/src/Boot.tsx`, del lado de los negocios, ya distinguía los tres
estados. Por eso las pruebas de ese lado sí mostraron «No se pudo contactar al
servidor» y señalaron el problema real.

## Qué se hizo

La pantalla de error sale de `Boot.tsx` a su propio componente,
`packages/shell/src/ServerUnavailable.tsx`, y se exporta desde el paquete. Dos
puertas la necesitan y tienen que decir lo mismo; duplicar el texto es como los
dos lados acaban describiendo la misma caída de forma distinta.

`Boot` la usa, y el panel de administración —que tiene su propia sesión y no
puede usar `Boot`— añade la rama que le faltaba, antes de la de `data == null`:

```tsx
if (session.isError) {
  return <ServerUnavailable error={...} />
}
```

La prueba, además, ahora afirma que **no** se ve la pantalla de error. Sin eso
seguiría siendo verde por casualidad el día que vuelva a pasar.

De paso: en `App.tsx` habían quedado dos textos de interfaz en inglés —
«expired» y «suspended» en el aviso de negocios vencidos — de la traducción del
código. La interfaz va en castellano; eran los únicos que se colaron, verificado
con un barrido de todo `web/`.

## Qué se descartó, y por qué

**Un botón de reintentar** en la pantalla de error. `Boot` no lo tiene, y la
administración debe sentirse el mismo producto. Recargar hace lo mismo.

**Mover la administración a `Boot`.** Entra por otra puerta y con otra sesión
—`/platform/me`, no las capacidades de un negocio— y forzarla ahí significaría
que `Boot` sepa de dos modelos de sesión para ahorrar cuatro líneas.

## Cómo se verificó

`npm run typecheck` en las cinco apps, `npm run build` y el presupuesto de
bundle: la super administración queda en 79,9 KB gzip de 220 (36%), sin cambio
apreciable. La prueba de usuario la valida el CI.

## Lo que quedó fuera

Nada. La conflación no aparece en ninguna otra pantalla: las cinco del lado de
los negocios pasan por `Boot`.
