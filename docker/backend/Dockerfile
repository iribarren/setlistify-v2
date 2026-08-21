# syntax=docker/dockerfile:1
#
# Setlistify backend image — FrankenPHP (decision D-1, docs/architecture.md).
# Multi-stage: a build stage carries Composer and the toolchain; the runtime
# stage carries only what actually serves traffic, as a dedicated non-root user.
#
# Base image pinned to tag + digest (AC-5.4). Update both together.
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.4@sha256:96560b9b3ec5be4f4784ebde460d4c176d7d05d801091b871bad56636bd822ab

################################################################################
# Build stage
################################################################################
FROM ${FRANKENPHP_IMAGE} AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# composer.json / composer.lock land here in prompt 01 (Symfony skeleton, out of
# scope for this feature). Until then there is nothing to install and this
# stage is a deliberate no-op, so the image still builds today and needs no
# change when the application code arrives.
COPY composer.jso[n] composer.loc[k] ./

RUN if [ -f composer.json ]; then \
      composer install --no-dev --no-interaction --no-scripts --no-progress --optimize-autoloader; \
    fi

################################################################################
# Runtime stage
################################################################################
FROM ${FRANKENPHP_IMAGE} AS runtime

ARG APP_UID=1000
ARG APP_GID=1000

# Serve on an unprivileged port so the runtime user never needs
# CAP_NET_BIND_SERVICE. Mapped to the host's documented port 8000 in compose.yaml.
ENV SERVER_NAME=:8080 \
    HOME=/home/appuser

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
  CMD curl --fail http://localhost:8080/ || exit 1
