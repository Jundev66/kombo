# Debian (trixie), not Alpine, and the reason matters.
#
# The Alpine image looked like the obvious choice on size. In practice,
# installing the PostgreSQL 18 client on Alpine drags in the whole LLVM and
# clang toolchain — `llvm22-libs` alone takes nearly six minutes on ARM —
# because the Postgres dev package depends on LLVM for its JIT compilation.
# Hundreds of megabytes and several minutes of compilation, never used.
#
# On Debian, `libpq-dev` drags in none of that. The build drops from over
# fifteen minutes to a couple, the final image weighs about the same, and we
# run on glibc rather than musl, which performs slightly better for PHP and
# springs fewer surprises with third-party extensions.
FROM php:8.5-fpm-trixie

# install-php-extensions resolves each extension's build dependencies itself
# and uninstalls them afterwards. Doing it by hand is thirty lines that break
# on every base image update.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# `pdo_pgsql` only: Laravel talks to PostgreSQL through PDO and never calls
# the `pg_*` functions, so the `pgsql` extension would be surplus. An unused
# dependency is surface that still has to be updated.
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
    # ── The PostgreSQL 18 client, and why the repository is needed ──
    #
    # Debian trixie ships client 17 and the server is 18. For `psql` it makes no
    # difference, but `pg_dump` refuses to dump a database of a higher version:
    # "server version 18 … pg_dump version 17". No client 18, no backups.
    #
    # It goes into development too, not just production, for a concrete reason:
    # that is where `backups:run` gets tested. A backup that can only be tested
    # on the customer's server gets tested the day it has to be restored.
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
