# Las cinco aplicaciones, compiladas.
#
# En producción **no hay servidores de Vite**: hay cinco carpetas de archivos
# estáticos que nginx sirve directamente. Es más rápido, no mantiene cinco
# procesos de Node vivos en un VPS pequeño, y elimina toda una clase de fallos
# —el recambio en caliente, los websockets del desarrollo— que en un servidor no
# tienen nada que hacer.
FROM node:24-alpine AS build

WORKDIR /app

# Los manifiestos primero: `npm ci` sólo se repite cuando cambian las
# dependencias, no cada vez que se toca un componente.
COPY web/package.json web/package-lock.json ./
COPY web/apps/portal/package.json ./apps/portal/
COPY web/apps/caja/package.json ./apps/caja/
COPY web/apps/panel/package.json ./apps/panel/
COPY web/apps/kds/package.json ./apps/kds/
COPY web/apps/admin/package.json ./apps/admin/
COPY web/packages/ui/package.json ./packages/ui/
COPY web/packages/shell/package.json ./packages/shell/
COPY web/packages/api-client/package.json ./packages/api-client/

RUN npm ci --no-audit --no-fund

COPY web/ ./

# El presupuesto de arranque ROMPE el build, también aquí. Una imagen que se
# construye igual con el portal a 300 KB sería un presupuesto decorativo.
RUN npm run build && npm run size

# ── Sólo los archivos ───────────────────────────────────────────────────────
#
# La imagen final no lleva Node ni node_modules: son cientos de megas para
# servir HTML.
FROM alpine:3.21 AS assets

COPY --from=build /app/apps/portal/dist /assets/portal
COPY --from=build /app/apps/caja/dist   /assets/caja
COPY --from=build /app/apps/panel/dist  /assets/panel
COPY --from=build /app/apps/kds/dist    /assets/cocina
COPY --from=build /app/apps/admin/dist  /assets/admin

# El contenedor no sirve nada: COPIA los archivos al volumen que nginx lee, y
# termina.
#
# Y lo copia SIEMPRE, en cada arranque. Ésta es la trampa que se lleva por
# delante un despliegue: Docker rellena un volumen con el contenido de la imagen
# **sólo la primera vez que lo crea**. Si esto viviera dentro de la imagen y ya
# está, el segundo despliegue construiría el frontend nuevo, arrancaría sin una
# queja, y seguiría sirviendo el de la semana pasada.
#
# El borrado previo importa igual: los nombres de los assets llevan hash, así
# que sin él el volumen acumularía para siempre los de todas las versiones.
CMD ["sh", "-c", "find /srv -mindepth 1 -delete && cp -a /assets/. /srv/ && echo 'Aplicaciones publicadas en /srv.'"]
