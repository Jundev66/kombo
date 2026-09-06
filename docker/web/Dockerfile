# The five apps, built.
#
# In production there are NO Vite servers: there are five directories of
# static files nginx serves directly. It is faster, it keeps five Node
# processes off a small VPS, and it removes a whole class of failure — hot
# reload, development websockets — that has no business on a server.
# tienen nada que hacer.
FROM node:24-alpine AS build

WORKDIR /app

# Manifests first: `npm ci` only repeats when the dependencies change, not
# every time a component is touched.
COPY web/package.json web/package-lock.json ./
COPY web/apps/portal/package.json ./apps/portal/
COPY web/apps/pos/package.json ./apps/pos/
COPY web/apps/dashboard/package.json ./apps/dashboard/
COPY web/apps/kds/package.json ./apps/kds/
COPY web/apps/admin/package.json ./apps/admin/
COPY web/packages/ui/package.json ./packages/ui/
COPY web/packages/shell/package.json ./packages/shell/
COPY web/packages/api-client/package.json ./packages/api-client/

RUN npm ci --no-audit --no-fund

COPY web/ ./

# The startup budget BREAKS the build here too. An image that builds happily
# with the portal at 300 KB would be a decorative budget.
RUN npm run build && npm run size

# ── Files only ──────────────────────────────────────────────────────────────
#
# The final image carries neither Node nor node_modules: hundreds of
# megabytes to serve HTML.
FROM alpine:3.21 AS assets

COPY --from=build /app/apps/portal/dist /assets/portal
COPY --from=build /app/apps/pos/dist   /assets/pos
COPY --from=build /app/apps/dashboard/dist  /assets/dashboard
COPY --from=build /app/apps/kds/dist    /assets/kds
COPY --from=build /app/apps/admin/dist  /assets/admin

# The container serves nothing: it COPIES the files to the volume nginx reads
# and exits.
#
# And it copies them ALWAYS, on every start. This is the trap that takes down
# a deployment: Docker fills a volume with the image's content ONLY the first
# time it creates it. If this lived inside the image and the volume already
# existed, the second deployment would build the new frontend, start without a
# complaint, and carry on serving last week's.
#
# The prior deletion matters too: asset names carry hashes, so without it the
# volume would accumulate every version's forever.
CMD ["sh", "-c", "find /srv -mindepth 1 -delete && cp -a /assets/. /srv/ && echo 'Aplicaciones publicadas en /srv.'"]
