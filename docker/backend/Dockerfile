# syntax=docker/dockerfile:1
#
# Setlistify backend image — FrankenPHP (decision D-1, docs/architecture.md).
# Multi-stage: a shared `base` stage carries the PHP extensions both later
# stages need; `build` carries Composer and the toolchain; `runtime` carries
# only what actually serves traffic, as a dedicated non-root user.
#
# Base image pinned to tag + digest (AC-5.4). Update both together.
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.4@sha256:96560b9b3ec5be4f4784ebde460d4c176d7d05d801091b871bad56636bd822ab

################################################################################
# Base stage — PHP extensions shared by build and runtime (D-9).
################################################################################
FROM ${FRANKENPHP_IMAGE} AS base

# pdo_pgsql: Doctrine's PostgreSQL driver.
# intl:      Symfony's validator/translation components require it.
# opcache:   production-grade PHP performance; safe to enable in dev too.
# zip:       Composer and a handful of packages expect it at install time.
# redis:     the PECL `redis` extension is used for the health-check PING and,
#            later, the Messenger transport (chosen over Predis for parity
#            with that future transport — see D-9 in the backend-skeleton spec).
RUN install-php-extensions pdo_pgsql intl opcache zip redis

################################################################################
# Build stage — production dependencies only (no dev toolchain in this image
# layer; the dev toolchain is installed on demand into the bind mount — D-9,
# AC-1.6).
################################################################################
FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.jso[n] composer.loc[k] ./

RUN if [ -f composer.json ]; then \
      composer install --no-dev --no-interaction --no-scripts --no-progress --optimize-autoloader; \
    fi

################################################################################
# Runtime stage
################################################################################
FROM base AS runtime

ARG APP_UID=1000
ARG APP_GID=1000

# Serve on an unprivileged port so the runtime user never needs
# CAP_NET_BIND_SERVICE. Mapped to the host's documented port 8000 in compose.yaml.
ENV SERVER_NAME=:8080 \
    HOME=/home/appuser \
    CADDY_SERVER_EXTRA_DIRECTIVES="header -Server"

# PHP's `expose_php` directive is what adds the `X-Powered-By: PHP/x.y.z` header — off everywhere,
# not just prod, so no response ever names the framework/runtime version (AC-9.7). Caddy's own
# `Server` header is dropped via CADDY_SERVER_EXTRA_DIRECTIVES above (the Caddyfile shipped in this
# base image reads that variable — see /etc/frankenphp/Caddyfile).
RUN { echo 'expose_php = Off'; } > /usr/local/etc/php/conf.d/99-no-expose-php.ini

# Composer is also present at runtime: local dev runs `docker compose exec
# backend composer install` (no --no-dev) against the bind-mounted ./backend,
# which is how the container gets PHPUnit/PHPStan/CS-Fixer without baking a
# dev-dependency layer into the image itself (D-9, AC-1.6).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dedicated non-root user. UID/GID are build args (default 1000, override with
# --build-arg APP_UID=$(id -u) --build-arg APP_GID=$(id -g)) so files the
# container writes into the bind-mounted project directory are owned by the
# developer, never root — no host-side chown workaround needed (AC-5.3).
RUN (getent group "${APP_GID}" || groupadd --gid "${APP_GID}" appuser) \
    && useradd --uid "${APP_UID}" --gid "${APP_GID}" --create-home --shell /bin/bash appuser \
    && mkdir -p /data/caddy /config/caddy /app \
    && chown -R appuser:appuser /data/caddy /config/caddy /app

WORKDIR /app

COPY --from=build --chown=appuser:appuser /app /app
COPY --chown=appuser:appuser . /app

# No secret ever reaches this image: only .env.example ships (see .dockerignore),
# and no ENV/ARG here carries a credential (AC-3.5). Real values are injected at
# container start from backend/.env.local (dev, bind-mounted) or the platform
# secret store (production) — never baked into a layer.

EXPOSE 8080

USER appuser

HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=5 \
  CMD curl --fail http://localhost:8080/api/health || exit 1
