# Debian (trixie), no Alpine, y la razón importa.
#
# La imagen de Alpine parecía la elección obvia por tamaño. En la práctica,
# instalar el cliente de PostgreSQL 18 en Alpine arrastra TODO el toolchain de
# LLVM y clang —`llvm22-libs` solo tarda casi seis minutos en ARM— porque el
# paquete de desarrollo de Postgres depende de LLVM para su compilación JIT.
# Son cientos de megas y varios minutos de compilación para no usarlos nunca.
#
# En Debian, `libpq-dev` no arrastra nada de eso. El build baja de más de
# quince minutos a un par, la imagen final pesa parecido, y de paso corremos
# sobre glibc en vez de musl, que para PHP rinde algo mejor y da menos
# sorpresas con extensiones de terceros.
FROM php:8.5-fpm-trixie

# install-php-extensions resuelve solo las dependencias de compilación de cada
# extensión y las desinstala después. Hacerlo a mano son treinta líneas que se
# rompen en cada actualización de la base.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Sólo `pdo_pgsql`: Laravel habla con PostgreSQL por PDO y nunca llama a las
# funciones `pg_*`, así que la extensión `pgsql` sobraría. Una dependencia que
# no se usa es superficie que hay que actualizar igual.
RUN install-php-extensions \
        pdo_pgsql \
        redis \
        intl \
        bcmath \
        opcache \
        zip \
        pcntl \
    && apt-get update \
    && apt-get install -y --no-install-recommends git gnupg curl ca-certificates \
    # ── El cliente de PostgreSQL 18, y por qué hace falta el repositorio ──
    #
    # Debian trixie trae el cliente 17 y el servidor es el 18. Para `psql` da
    # igual, pero `pg_dump` **se niega a volcar una base de versión superior**:
    # «server version 18 … pg_dump version 17». Sin cliente 18 no hay respaldos.
    #
    # Va también en desarrollo, y no sólo en producción, por una razón concreta:
    # es donde se prueba `respaldos:hacer`. Un respaldo que sólo se puede probar
    # en el servidor del cliente se prueba el día que hace falta restaurarlo.
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] \
        https://apt.postgresql.org/pub/repos/apt trixie-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-18 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/kombo.ini

WORKDIR /var/www/api

CMD ["php-fpm"]
