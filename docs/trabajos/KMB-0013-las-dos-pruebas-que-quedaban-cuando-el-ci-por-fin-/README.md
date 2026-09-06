---
codigo: KMB-0013
titulo: Las dos pruebas que quedaban cuando el CI por fin pudo correr
tipo: arreglo
estado: hecho
fecha: 2026-09-06
toca: [api/config/logging.php, api/database/seeders/DemoTenantsSeeder.php]
relacionados: [KMB-0012, KMB-0009]
---

# KMB-0013 · Las dos pruebas que quedaban cuando el CI por fin pudo correr

## Por qué

Arreglado [[KMB-0012]], la suite pasó de **68 fallidas y 2 pasadas en 31,6
minutos** a **80 pasadas y 2 fallidas en 4,4**. Con el ruido fuera, las dos que
quedaban resultaron ser fallos de verdad que nadie había podido ver: el CI nunca
había llegado tan lejos.

**`bot.spec.ts:227` esperaba 403 y recibía 500.** Un webhook sin firma no debe
entrar, y el sistema lo rechazaba bien — pero al ir a *anotarlo* moría. El
diagnóstico estaba en una línea de `ls -la`:

```
-rw-r--r-- 1 root root  582 Sep  6 16:39 laravel.log
```

Lo creó root, con modo 644. `artisan` y el trabajador de colas corren como root;
php-fpm sirve como `www-data`. El primero que escribe en el log se queda con el
fichero, y aquí el primero fue la cola. Después, la petición que sólo quería
dejar constancia de un rechazo no podía escribir, Monolog lanzaba, y un 403
se convertía en un 500.

**`menu.spec.ts:133` esperaba más de una página de productos y encontraba una.**
El comentario de la prueba decía «la siembra de demostración siempre los tiene».
No era cierto: los seeders **no creaban ni un solo producto**. En local pasaba
porque la base tenía 218 productos en `elsazon`, acumulados de correr
`journey.spec.ts` cientos de veces —crea dos cada vez—. Esa prueba nunca había
pasado sobre una base limpia.

## Qué se hizo

`api/config/logging.php`: `'permission' => 0666` en los tres canales de fichero.
Va **en el canal**, no en la raíz del fichero de configuración: Laravel lo lee de
ahí y lo pasa al `StreamHandler` de Monolog.

`api/database/seeders/DemoTenantsSeeder.php`: la arepera recibe su carta —seis
categorías y sesenta y tres productos—. El tamaño no es adorno: la pantalla de
productos pagina de cincuenta en cincuenta, y una carta que cabe en una página
no ejercita nunca el aviso de «se ven N de M», que es justo lo que vigila
[[KMB-0009]]. La siembra corre antes de cada corrida, así que la carta se salta
si el negocio ya tiene categorías; sin eso se duplicaría cada vez.

La Esquina se queda sin carta a propósito: es el negocio que necesita la prueba
«un menú vacío dice qué hacer». Ese reparto ya estaba en las pruebas; lo que
faltaba era el otro lado.

## Qué se descartó, y por qué

**Bajar la exigencia de la prueba del menú** —saltarla si sólo hay una página—
esconde exactamente la regresión que vigila.

**Que la prueba se cree sus sesenta productos** por la API: cincuenta y una
peticiones antes de la primera aserción, y una prueba que tarda más en preparar
el terreno que en comprobar nada.

**Correr los contenedores de PHP como `www-data`** arreglaría el permiso de raíz,
pero cambia la propiedad de los ficheros en el disco de quien desarrolla y toca
mucho más de lo que el fallo justifica.

## Qué falló por el camino

`'permission' => 0666` se puso primero en la raíz de `logging.php`, donde no hace
nada: Laravel lo busca en la configuración del canal.

## Cómo se verificó

En local no se pudo: la máquina de desarrollo da ~2 s por petición de PHP
—`opcache.validate_timestamps=1` sobre el montaje de Windows hace `stat` de miles
de ficheros en cada una— y la suite tarda hora y media, fallando por tiempos
agotados. Sí se comprobaron sintaxis y estilo, y que la carta no rompe ninguna
aserción de lista: todas filtran por datos de su propia corrida.

La verificación real la hace el CI, que desde [[KMB-0012]] corre la suite entera
en 4,4 minutos sobre una base limpia — que es exactamente la condición en la que
estos dos fallos se ven y en la que la máquina de desarrollo, sucia de corridas
anteriores, los escondía.

## Lo que quedó fuera

`web/apps/admin/src/App.tsx` sigue confundiendo «servidor caído» con «no hay
sesión»: hace `if (session.data == null) return <LoginScreen/>` sin mirar
`session.isError`. Por eso `admin.spec.ts:28` estaba verde contra una API
completamente caída durante todo [[KMB-0012]]. `packages/shell/src/Boot.tsx`
distingue los tres estados y es el patrón que debería seguir.
