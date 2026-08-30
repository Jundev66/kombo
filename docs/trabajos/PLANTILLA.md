---
codigo: KMB-XXXX
titulo: Una frase que diga QUÉ cambia, no cómo
tipo: funcionalidad        # funcionalidad | arreglo | decision | plan
estado: en-curso           # propuesto | en-curso | hecho | descartado
fecha: AAAA-MM-DD
toca: []                   # rutas: [api/src/Platform/Auth, web/apps/panel]
relacionados: []           # otros códigos: [KMB-0003]
---

# KMB-XXXX · <título>

## Por qué

Qué problema había. **Con el síntoma concreto**, no en abstracto: «el panel
enseñaba 50 de 693 productos y nada lo insinuaba», no «faltaba paginación».

Si el problema se veía en pantalla, dilo como se veía. Si engañaba, di cómo
engañaba — ésa es la parte que no está en el código.

## Qué se hizo

Lo que cambió, en dos o tres párrafos. Nombra los archivos que importan; no
enumeres el diff, que ya está en git.

## Qué se descartó, y por qué

**La sección que más vale.** Un registro que sólo cuenta lo que se hizo obliga
a redescubrir por qué no se hizo lo otro. Si sólo había un camino, dilo y
sigue.

## Qué falló por el camino

Lo que se rompió, lo que se creyó y no era, la prueba que pasó por la razón
equivocada. No es una confesión: es lo que evita que el siguiente tropiece
igual.

## Cómo se verificó

Los comandos que se corrieron y lo que se miró a ojo. Si algo quedó sin
verificar, dilo aquí.

## Lo que quedó fuera

Lo que se decidió no hacer ahora, para que no parezca olvido.
