# Kombo

Sistema de pedidos de comida **multi-negocio**. Un solo despliegue atiende a
todos los clientes: cada negocio entra por su subdominio y ve únicamente sus
datos, garantizado por Row Level Security de PostgreSQL.

Un pedido entra por una de tres puertas —el **portal** del cliente, un **bot**
de WhatsApp o Telegram, o la **caja** del mostrador— y las tres desembocan en
la misma **pantalla de cocina**. Cuando la comanda está lista, sale de cocina y
el cliente se entera por donde pidió.

**Laravel 13 · PHP 8.5 · PostgreSQL 18 · Redis 8 · React 19 · Vite 8 ·
TypeScript 7 · Tailwind 4 · Playwright**

## Arrancar

```bash
make up      # levantar todo
make setup   # primera vez: clave, migraciones, base de pruebas
make demo    # sembrar los negocios de demostración
```

```
http://elsazon.localhost:8010/          portal del cliente
http://elsazon.localhost:8010/panel/    panel del dueño
http://elsazon.localhost:8010/caja/     caja
http://elsazon.localhost:8010/cocina/   pantalla de cocina
http://admin.localhost:8010/            super administración
```

## Verificar

```bash
make check   # arquitectura, aislamiento, suite, estilo, tipos y presupuesto
make e2e     # pruebas de usuario por el navegador
```

## Poner esto en un servidor

- **[`docs/despliegue.md`](docs/despliegue.md)** — de un VPS vacío a un negocio
  tomando pedidos: DNS comodín, Cloudflare, `compose.prod.yml`, y las cuatro
  comprobaciones que se hacen al terminar.
- **[`docs/canales.md`](docs/canales.md)** — conectar el WhatsApp y el Telegram
  de cada negocio: de dónde sale cada credencial y qué hacer cuando el bot no
  contesta.
- **[`docs/respaldos.md`](docs/respaldos.md)** — qué se guarda, dónde, y sobre
  todo **cómo se restaura**. Restaura uno el día del despliegue: un respaldo que
  nadie ha restaurado nunca no es un respaldo.

## Para entender el sistema

El punto de entrada es **[`AGENTS.md`](AGENTS.md)** — el mapa, los invariantes
y lo que no se puede romper. Sirve igual a una persona nueva que a cualquier IA
que entre al repositorio. Cada carpeta grande tiene el suyo:
[`api/`](api/AGENTS.md), [`web/`](web/AGENTS.md), [`e2e/`](e2e/AGENTS.md).

`CLAUDE.md`, `GEMINI.md`, `.cursorrules` y `.github/copilot-instructions.md`
son punteros de tres líneas que traen ahí: cada herramienta busca su propio
nombre y **el texto es uno solo**. Si mañana entras con otra que lee un nombre
distinto, se añade el puntero — no se copia el texto.

Y **[`docs/trabajos/`](docs/trabajos/README.md)** es el porqué de cómo están
las cosas: un trabajo por carpeta con su código `KMB-XXXX`, con lo que se
descartó, lo que falló al hacerlo y cómo se verificó. Los códigos se citan
desde los comentarios del código, así que un `// KMB-0009` en el sitio exacto
lleva al documento que lo explica.

```bash
make trabajo t="Lo que voy a hacer"   # abrir el siguiente
make trabajos                         # regenerar el índice
```

## Nota sobre documentos fiscales

La caja emite **notas de entrega**, no facturas: documento comercial con
correlativo propio, con `No es una factura` impreso. El sistema no calcula IVA
como débito fiscal, no lleva libro de ventas y no numera con rangos de la
autoridad. Una nota de entrega no sustituye a la factura ni elimina las
obligaciones tributarias del negocio; emitir facturas exige los medios
autorizados por el SENIAT.

Hay un puerto `FiscalDocument` con implementación nula por si un negocio se
homologa más adelante. Mientras no exista ese adaptador, no hay opción
escondida que convierta una nota en factura.
