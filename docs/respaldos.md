# Respaldos, y cómo restaurarlos

La segunda mitad de este documento es la que importa. Un respaldo que nadie ha
restaurado nunca no es un respaldo: es un archivo del que se supone algo.

**Restaura uno el día que despliegues**, con el sistema todavía vacío y sin
nadie mirando. Es media hora, y es la única forma de saber que funciona.

---

## Qué se guarda

Dos archivos por respaldo, y hacen falta **los dos**:

| Archivo                        | Qué lleva                                   |
|--------------------------------|---------------------------------------------|
| `2026-08-28_034000-base.dump`  | la base de datos entera                     |
| `…-archivos.tar.gz`            | `storage/app`: comprobantes de pago y fotos |

> Restaurar sólo la base deja todas las notas de entrega apuntando a un
> comprobante que ya no existe — y el comprobante es justo lo que se mira cuando
> un cliente dice que sí pagó.

**Dónde quedan:**

- En el servidor, en un volumen propio. Se conservan **14** copias.
- Fuera del servidor, en S3 (o R2, o cualquier compatible), si está configurado.

Las dos hacen falta, y protegen de cosas distintas: la local, del error humano
—alguien borró algo y hay que volver una hora atrás—; la remota, del incendio.
El día que se pierde la máquina, se pierde con el respaldo dentro.

**Cuándo:** todos los días a las 3:40 de la madrugada, por el planificador. A
esa hora ningún negocio de comida está cobrando.

---

## Configurar la copia de fuera

Sirve cualquier almacenamiento compatible con S3. **Cloudflare R2** encaja bien
porque no cobra por sacar los datos, que es exactamente lo que haces el día
malo.

En el `.env` del servidor:

```bash
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_BUCKET=kombo-respaldos
AWS_DEFAULT_REGION=auto
AWS_ENDPOINT=https://<id-de-cuenta>.r2.cloudflarestorage.com
```

Y recrear los contenedores para que lo tomen:

```bash
docker compose -f compose.prod.yml up -d --force-recreate api queue scheduler
```

> **Pon una regla de caducidad en el bucket** (30 o 60 días). La rotación de 14
> copias es sólo la del servidor; arriba, sin regla, se acumulan para siempre y
> la factura crece sola.

---

## Hacer uno a mano

```bash
docker compose -f compose.prod.yml exec api php artisan backups:run
```

```
Base:     2026-08-28_141203-base.dump  (4.2 MB)
Archivos: 2026-08-28_141203-archivos.tar.gz  (18.6 MB)
Subido fuera del servidor.
```

Hazlo **antes de cada actualización que traiga migraciones**.

Opciones: `--no-cloud` (sólo local), `--keep=30` (cuántas locales guardar).

---

## Comprobar que se están haciendo

Cada respaldo queda escrito en la bitácora, salga bien o salga mal:

```bash
docker compose -f compose.prod.yml exec postgres \
  psql -U kombo_owner -d kombo -c \
  "SELECT created_at, action, details->>'base' AS archivo
     FROM platform_audit_log
    WHERE action LIKE 'backup.%'
    ORDER BY created_at DESC LIMIT 10"
```

Lo que se busca: una línea `backup.made` de anoche. Un `backup.failed`, o el
silencio, son la misma noticia.

**Míralo de vez en cuando.** Sin nadie que avise, la forma habitual de descubrir
que los respaldos llevan tres semanas fallando es necesitar uno.

---

## Bajarse un respaldo

```bash
# Del servidor a tu máquina
docker compose -f compose.prod.yml cp \
  api:/var/www/api/storage/respaldos ./respaldos

# O ver qué hay antes
docker compose -f compose.prod.yml exec api ls -lh storage/respaldos
```

---

# Restaurar

Lo de abajo **borra la base de datos actual y la sustituye**. Léelo entero antes
de escribir nada.

## 0. Antes de tocar nada

Aunque lo que haya esté roto, respáldalo. Restaurar sobre un desastre es cómo se
pierde la única pista de qué pasó.

```bash
cd ~/kombo
docker compose -f compose.prod.yml exec api php artisan backups:run --no-cloud
```

## 1. Elegir la copia

```bash
docker compose -f compose.prod.yml exec api ls -1 storage/respaldos
```

Si viene de S3, bájala y déjala dentro del contenedor:

```bash
docker compose -f compose.prod.yml cp \
  ./2026-08-28_034000-base.dump api:/var/www/api/storage/respaldos/
docker compose -f compose.prod.yml cp \
  ./2026-08-28_034000-archivos.tar.gz api:/var/www/api/storage/respaldos/
```

## 2. Parar lo que escribe

```bash
docker compose -f compose.prod.yml stop nginx api queue scheduler
```

**En este orden**: nginx primero, para que deje de entrar nada nuevo. La base y
Redis se quedan levantados.

## 3. La base de datos

`pg_restore` **no puede** dejar caer una base a la que está conectado, así que
se hace desde `postgres`, la base de mantenimiento:

```bash
MARCA=2026-08-28_034000

# Cortar las conexiones que queden
docker compose -f compose.prod.yml exec postgres \
  psql -U kombo_owner -d postgres -c \
  "SELECT pg_terminate_backend(pid) FROM pg_stat_activity
    WHERE datname = 'kombo' AND pid <> pg_backend_pid()"

# Fuera la vieja, dentro una vacía
docker compose -f compose.prod.yml exec postgres \
  psql -U kombo_owner -d postgres -c "DROP DATABASE kombo"
docker compose -f compose.prod.yml exec postgres \
  psql -U kombo_owner -d postgres -c "CREATE DATABASE kombo OWNER kombo_owner"
```

> **La base nueva llega sin los permisos de `kombo_app`.** El script de roles
> sólo corre con el directorio de datos vacío, así que hay que volver a
> otorgarlos —el paso 5 lo hace—. Saltárselo deja la aplicación sin poder leer
> nada y el síntoma engaña: la web carga y todo sale vacío.

Y ahora sí:

```bash
docker compose -f compose.prod.yml exec api \
  sh -c "PGPASSWORD=\$DB_OWNER_PASSWORD pg_restore \
    --host=postgres --username=kombo_owner --dbname=kombo \
    --no-owner --no-privileges \
    storage/respaldos/${MARCA}-base.dump"
```

`pg_restore` puede escupir avisos sobre objetos que ya existen. No pasa nada: lo
que importa es que no haya errores de datos.

## 4. Los archivos

```bash
docker compose -f compose.prod.yml exec api \
  tar -xzf storage/respaldos/${MARCA}-archivos.tar.gz -C storage/app
```

## 5. Los permisos de `kombo_app`

**Este paso no se salta.** Sin él la aplicación conecta y no ve una sola fila:

```bash
docker compose -f compose.prod.yml exec postgres psql -U kombo_owner -d kombo <<'SQL'
GRANT USAGE ON SCHEMA public TO kombo_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO kombo_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO kombo_app;
ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO kombo_app;
ALTER DEFAULT PRIVILEGES FOR ROLE kombo_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO kombo_app;
SQL
```

## 6. Levantar y limpiar la caché

```bash
docker compose -f compose.prod.yml start api queue scheduler nginx

# Redis todavía tiene la resolución de subdominios de ANTES de restaurar.
docker compose -f compose.prod.yml exec api php artisan cache:clear
```

> Sin esto el síntoma es de los que cuestan una tarde: el portal carga bien —la
> resolución viene de caché— y **todas** las consultas devuelven cero filas,
> porque RLS está filtrando por un identificador de negocio que ya no existe.

## 7. Comprobar

No basta con que cargue. Cuatro cosas:

```bash
# Los negocios están
docker compose -f compose.prod.yml exec postgres \
  psql -U kombo_owner -d kombo -c "SELECT slug, name, status FROM tenants"

# Y los pedidos
docker compose -f compose.prod.yml exec postgres \
  psql -U kombo_owner -d kombo -c "SELECT count(*), max(placed_at) FROM orders"
```

Y con el navegador:

1. El portal de un negocio muestra su menú, con las fotos.
2. Entra al panel con la cuenta del dueño.
3. Abre una nota de entrega que tuviera comprobante, y **ábrelo**. Si el
   comprobante no se ve, el tar no se extrajo donde debía.

---

## Restaurar sólo una tabla

El caso más común no es el desastre: es que alguien borró el catálogo de un
negocio. Para eso no hace falta volver atrás la base entera.

Los volcados son de formato `custom`, así que:

```bash
# Qué hay dentro
docker compose -f compose.prod.yml exec api \
  pg_restore --list storage/respaldos/${MARCA}-base.dump | grep products

# Sólo esa tabla, a una base aparte para mirarla sin tocar la de verdad
docker compose -f compose.prod.yml exec postgres \
  psql -U kombo_owner -d postgres -c "CREATE DATABASE kombo_rescate OWNER kombo_owner"

docker compose -f compose.prod.yml exec api \
  sh -c "PGPASSWORD=\$DB_OWNER_PASSWORD pg_restore \
    --host=postgres --username=kombo_owner --dbname=kombo_rescate \
    --no-owner --table=products storage/respaldos/${MARCA}-base.dump"
```

Desde ahí se copian a mano las filas que hagan falta — **filtrando por
`tenant_id`**, que en `kombo_rescate` no hay RLS que lo haga por ti.

Al terminar: `DROP DATABASE kombo_rescate`.

---

## Cuando el respaldo falla

**`server version 18; pg_dump version 17`** — la imagen quedó con el cliente
viejo. El `Dockerfile` añade el repositorio de PostgreSQL para tener el 18;
reconstruye:

```bash
docker compose -f compose.prod.yml up -d --build api
```

**`No space left on device`** — el disco está lleno. Baja la retención y borra a
mano:

```bash
docker compose -f compose.prod.yml exec api php artisan backups:run --keep=5
```

Un disco lleno no es sólo que no haya respaldo: PostgreSQL deja de aceptar
escrituras y el negocio no puede cobrar.

**«La copia local está hecha, pero la subida falló»** — los datos están a salvo
en el servidor; lo que falla son las credenciales de S3 o el endpoint. Nunca se
borra la copia local por un fallo de subida.
