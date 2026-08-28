# `e2e/` · Pruebas de usuario con Playwright

Lee antes el `CLAUDE.md` de la raíz.

## Qué va aquí y qué va en Pest

| Pregunta | Dónde |
|---|---|
| ¿Esta regla de negocio es correcta? | Pest, `tests/Unit` |
| ¿Este endpoint responde bien con este permiso? | Pest, `tests/Feature` |
| ¿Se puede ver lo de otro negocio? | Pest, `tests/Isolation` |
| **¿Una persona puede completar esto de principio a fin?** | Aquí |

Si puedes responderlo sin abrir un navegador, no va aquí: un E2E cuesta veinte
veces más y falla por veinte razones más.

## Las reglas duras

**Un worker, en serie, cero reintentos.** Las pruebas comparten los negocios
sembrados. Y un reintento convierte una prueba intermitente en una que pasa —y
que por tanto nadie arregla—. Si falla una de cada diez veces, eso *es* el bug.

**Selectores por rol y etiqueta. Nunca clase ni `data-testid`.** Si un control
no se alcanza con `getByRole`, el arreglo está en el componente, no en la
prueba. Dos trampas conocidas:

- `getByRole` **antes** que `getByLabel` en campos obligatorios: `getByLabel`
  compara contra el texto, que incluye el asterisco; el nombre accesible no.
- Cuidado con nombres que se contienen: `{ name: 'Cerrar' }` también encuentra
  «Cerrar sesión». Usa `exact: true`.

**Nunca `waitForTimeout`.** `waitForResponse` sólo cuando la petición la
dispara tu propio clic y hay una sola.

**Afirma la causa, no sólo el síntoma.** Comprueba lo que se ve *y* lo que vino
en la respuesta. Una prueba que sólo mira la pantalla pasa en verde con
`display: none`.

**Las llamadas a la API van con `fetch` dentro de la página**, vía
`page.evaluate`. El contexto de peticiones de Playwright resuelve nombres con
Node y no ve `--host-resolver-rules`, así que dentro del contenedor
`elsazon.localhost` sencillamente no existe. Y desde la página viaja la cookie
de sesión de ese origen, que es lo que queremos probar.

## La trampa de los datos

La siembra es **aditiva**: reusa el negocio si existe y no borra nada. De ahí
salen tres reglas:

1. **Lo que una prueba cambia, esa prueba lo restaura** en un `afterEach`.
2. **Lo que crea, lo marca** — prefijo `[e2e]` más un sufijo por corrida.
3. **Los ayudantes se autocorrigen**: `cerrarTurno()` no hace nada si no hay
   turno abierto.

Hay un tercer estado que no vive ni en la base sembrada ni en el navegador: el
**turno de caja abierto**, que vive en el servidor. Es la fuente clásica de una
suite que pasa la primera vez y falla la segunda.

## Subdominios dentro del contenedor

`E2E_RESOLVER=nginx:80` se convierte en
`--host-resolver-rules=MAP *.localhost nginx:80`. **Es un comodín**, igual que
el `server_name` de nginx y que el DNS de producción: un negocio nuevo no toca
nada. Si te ves escribiendo cinco negocios en `extra_hosts`, estás enumerando
clientes.

## Correr

```bash
./e2e/run.sh                        # todas (siembra antes)
./e2e/run.sh tests/cocina.spec.ts   # una
./e2e/run.sh --grep "comanda"
./e2e/run.sh --limpio               # rehaciendo la base primero
```

La versión de `@playwright/test` está fijada **exacta** y debe coincidir con la
etiqueta de la imagen en `compose.yml`. Una diferencia se manifiesta como un
fallo de protocolo a mitad de prueba, que parece intermitencia y no lo es.

Trazas en `resultados/`, informe en `reportes/`.

## Dos trampas que cuestan una tarde

**No esperes a un estado que ya podía estar puesto.** La base NO se rehace entre
corridas: si una prueba conecta un canal y espera a que diga «Conectado», y una
corrida anterior ya lo dejó conectado, la aserción pasa al instante y lo que
venga después corre contra datos viejos. Se espera a algo que **sólo puede pasar
por lo que hizo esta prueba** — que el formulario se cierre, por ejemplo.

**Lo que se procesa en la cola se espera con `expect.poll`, no con un sleep.** Y
se espera al RESULTADO final, no a un paso intermedio: esperar a que exista la
conversación y leer sus mensajes acto seguido deja unos milisegundos en los que
la respuesta del bot todavía no está escrita. Es una prueba intermitente
esperando a que alguien la maldiga.
