# syntax=docker/dockerfile:1

#
# MariaDB dependencies build step
#
FROM mariadb:lts-noble AS mariadb

#
# Built-in docs build step
#
FROM ghcr.io/azuracast/azuracast.com:builtin@sha256:f7c41278613d113375ca285ba85f770c6f1daf2a08d8a3da42540389b15a2647 AS docs

#
# AzuraCast Icecast web assets (pinned image digest)
#
FROM ghcr.io/azuracast/icecast-ac:2025-12-29@sha256:4a6b7cdfa5382e1e71a9e97d27c13ce3e188f73e13cb499117db39d7ce73b52a AS icecast-assets

#
# Icecast 2.5 stable build step
#
FROM debian:trixie-slim AS icecast

ARG LIBIGLOO_VERSION=0.9.5
ARG LIBIGLOO_SHA256=ea22e9119f7a2188810f99100c5155c6762d4595ae213b9ac29e69b4f0b87289
ARG ICECAST_VERSION=2.5.0
ARG ICECAST_SHA512=d92ce5d8ae1cd011eaa8c7424adea744f35e5c2d3e8244d362743be1c6bbc8fc44d76d7a212cf1eebe79da9b7d83b2ed5ab8659fb97929af316674b5ddf590b5

RUN apt-get update \
    && apt-get install -q -y --no-install-recommends \
        build-essential ca-certificates curl pkg-config \
        libcurl4-openssl-dev librhash-dev libssl-dev libvorbis-dev libxml2-dev libxslt1-dev \
    && curl -fsSL \
        "https://downloads.xiph.org/releases/igloo/libigloo-${LIBIGLOO_VERSION}.tar.gz" \
        -o /tmp/libigloo.tar.gz \
    && echo "${LIBIGLOO_SHA256}  /tmp/libigloo.tar.gz" | sha256sum --check --strict \
    && mkdir /tmp/libigloo \
    && tar -xzf /tmp/libigloo.tar.gz --strip-components=1 -C /tmp/libigloo \
    && cd /tmp/libigloo \
    && ./configure --prefix=/usr/local \
    && make -j"$(nproc)" \
    && make install \
    && curl -fsSL \
        "https://downloads.xiph.org/releases/icecast/icecast-${ICECAST_VERSION}.tar.gz" \
        -o /tmp/icecast.tar.gz \
    && echo "${ICECAST_SHA512}  /tmp/icecast.tar.gz" | sha512sum --check --strict \
    && mkdir /tmp/icecast \
    && tar -xzf /tmp/icecast.tar.gz --strip-components=1 -C /tmp/icecast \
    && cd /tmp/icecast \
    && PKG_CONFIG_PATH=/usr/local/lib/pkgconfig ./configure --prefix=/usr/local \
    && make -j"$(nproc)" \
    && make install \
    && ldconfig

# Preserve only AzuraCast's custom status/fallback assets, not the KH binary or KH web UI.
COPY --from=icecast-assets /usr/local/share/icecast/web/error.mp3 /usr/local/share/icecast/web/error.mp3
COPY --from=icecast-assets /usr/local/share/icecast/web/fallback* /usr/local/share/icecast/web/
COPY --from=icecast-assets /usr/local/share/icecast/web/status-json.xsl /usr/local/share/icecast/web/status-json.xsl
COPY --from=icecast-assets /usr/local/share/icecast/web/xml2json.xslt /usr/local/share/icecast/web/xml2json.xslt

#
# PHP Extension Installer build step
#
FROM mlocati/php-extension-installer AS php-extension-installer

# ---

FROM scratch AS dependencies

# Add PHP extension installer tool
COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Add MariaDB dependencies
COPY --from=mariadb /usr/local/bin/healthcheck.sh /usr/local/bin/db_healthcheck.sh
COPY --from=mariadb /usr/local/bin/docker-entrypoint.sh /usr/local/bin/db_entrypoint.sh

# Add Icecast
COPY --from=icecast /usr/local/bin/icecast /usr/local/bin/icecast
COPY --from=icecast /usr/local/share/icecast /usr/local/share/icecast
COPY --from=icecast /usr/local/lib/libigloo* /usr/local/lib/

#
# Final build image
#
FROM php:8.5-fpm-trixie AS pre-final

ENV TZ="UTC" \
    LANGUAGE="en_US.UTF-8" \
    LC_ALL="en_US.UTF-8" \
    LANG="en_US.UTF-8" \
    LC_TYPE="en_US.UTF-8"

COPY --link --from=dependencies / /

# Register the libigloo shared libraries copied from the Icecast build stage.
RUN ldconfig

# Run base build process
RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    bash /bd_build/prepare.sh && \
    bash /bd_build/add_user.sh && \
    bash /bd_build/cleanup.sh

# Build each set of dependencies in their own step for cacheability.
RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    --mount=type=bind,source=./util/docker/supervisor,target=/bd_build/supervisor,rw \
    bash /bd_build/supervisor/setup.sh && \
    bash /bd_build/cleanup.sh

RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    --mount=type=bind,source=./util/docker/stations,target=/bd_build/stations,rw \
    bash /bd_build/stations/setup.sh && \
    bash /bd_build/cleanup.sh

RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    --mount=type=bind,source=./util/docker/web,target=/bd_build/web,rw \
    bash /bd_build/web/setup.sh && \
    bash /bd_build/cleanup.sh

RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    --mount=type=bind,source=./util/docker/mariadb,target=/bd_build/mariadb,rw \
    bash /bd_build/mariadb/setup.sh && \
    bash /bd_build/cleanup.sh

RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    --mount=type=bind,source=./util/docker/redis,target=/bd_build/redis,rw \
    bash /bd_build/redis/setup.sh && \
    bash /bd_build/cleanup.sh

RUN --mount=type=bind,source=./util/docker/common,target=/bd_build,rw \
    bash /bd_build/chown_dirs.sh

# Add built-in docs
COPY --from=docs --chown=azuracast:azuracast /dist /var/azuracast/docs

USER azuracast

RUN touch /var/azuracast/.docker

USER root

VOLUME "/var/azuracast/stations"
VOLUME "/var/azuracast/backups"
VOLUME "/var/lib/mysql"
VOLUME "/var/azuracast/storage/acme"
VOLUME "/var/azuracast/storage/geoip"
VOLUME "/var/azuracast/storage/rsas"
VOLUME "/var/azuracast/storage/sftpgo"
VOLUME "/var/azuracast/storage/shoutcast2"
VOLUME "/var/azuracast/storage/stereo_tool"
VOLUME "/var/azuracast/storage/uploads"

EXPOSE 80 443 2022

# Sensible default environment variables.
ENV LANG="en_US.UTF-8" \
    PATH="${PATH}:/var/azuracast/storage/shoutcast2" \
    APPLICATION_ENV="production" \
    MYSQL_HOST="localhost" \
    MYSQL_PORT=3306 \
    MYSQL_USER="azuracast" \
    MYSQL_PASSWORD="azur4c457" \
    MYSQL_DATABASE="azuracast" \
    ENABLE_REDIS="true" \
    REDIS_HOST="localhost" \
    REDIS_PORT=6379 \
    REDIS_DB=0 \
    NGINX_RADIO_PORTS="default" \
    NGINX_WEBDJ_PORTS="default" \
    COMPOSER_PLUGIN_MODE="false" \
    ADDITIONAL_MEDIA_SYNC_WORKER_COUNT=0 \
    PROFILING_EXTENSION_ENABLED=0 \
    PROFILING_EXTENSION_ALWAYS_ON=0 \
    PROFILING_EXTENSION_HTTP_KEY=dev \
    PROFILING_EXTENSION_HTTP_IP_WHITELIST=* \
    ENABLE_WEB_UPDATER="true"

#
# Development Build
#
FROM pre-final AS development

# Dev build step
COPY ./util/docker/common /bd_build/
COPY ./util/docker/dev /bd_build/dev

RUN bash /bd_build/dev/setup.sh \
    && bash /bd_build/cleanup.sh \
    && rm -rf /bd_build

USER azuracast

WORKDIR /var/azuracast/www

COPY --chown=azuracast:azuracast composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache,uid=1000,gid=1000 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install --no-ansi --no-interaction \
    && composer clear-cache

COPY --chown=azuracast:azuracast package.json package-lock.json ./
RUN --mount=type=cache,target=/home/azuracast/.npm \
    npm ci --include=dev \
    && npm cache clean --force

COPY --chown=azuracast:azuracast . .

USER root

# Install Kokoro TTS for human-like AI DJ voices (same as production final stage).
# kokoro-v1.0.onnx + voices-v1.0.bin ship the FULL Kokoro v1.0 voice set (~53 speakers).
RUN pip install --break-system-packages kokoro-onnx soundfile \
    && mkdir -p /opt/kokoro \
    && curl -L -o /opt/kokoro/kokoro-v1.0.onnx \
       https://github.com/thewh1teagle/kokoro-onnx/releases/download/model-files-v1.0/kokoro-v1.0.onnx \
    && curl -L -o /opt/kokoro/voices-v1.0.bin \
       https://github.com/thewh1teagle/kokoro-onnx/releases/download/model-files-v1.0/voices-v1.0.bin

COPY --chown=root:root util/docker/kokoro/kokoro_tts.py /opt/kokoro/kokoro_tts.py
RUN chmod +x /opt/kokoro/kokoro_tts.py

# Sensible default environment variables.
ENV APPLICATION_ENV="development" \
    PROFILING_EXTENSION_ENABLED=1 \
    ENABLE_WEB_UPDATER="false"

# Entrypoint and default command
ENTRYPOINT ["tini", "--", "/usr/local/bin/my_init"]
CMD ["--no-main-command"]

# Frontend Build Step (Vite/Node)
#
FROM node:20-slim AS frontend-build
WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci
COPY . .
RUN npm run build

#
# Final Production Image
#
FROM pre-final AS final

USER azuracast
WORKDIR /var/azuracast/www

COPY --chown=azuracast:azuracast . .

COPY --from=frontend-build --chown=azuracast:azuracast /app/web/static/vite_dist /var/azuracast/www/web/static/vite_dist

COPY --chown=azuracast:azuracast .version /var/azuracast/www/.version

USER root


RUN rm -rf /var/azuracast/www/bin /var/azuracast/www/src /var/azuracast/www/config /var/azuracast/www/templates && \
    ln -s /var/azuracast/www/backend/bin /var/azuracast/www/bin && \
    ln -s /var/azuracast/www/backend/src /var/azuracast/www/src && \
    ln -s /var/azuracast/www/backend/config /var/azuracast/www/config && \
    ln -s /var/azuracast/www/backend/templates /var/azuracast/www/templates


RUN ln -sf /var/azuracast/azuracast.env /var/azuracast/www/azuracast.env

RUN mkdir -p /var/azuracast/storage/logs \
             /var/azuracast/storage/cache \
             /var/azuracast/storage/sessions \
             /var/azuracast/storage/temp && \
    chown -R azuracast:azuracast /var/azuracast/storage && \
    chmod -R 777 /var/azuracast/storage


RUN rm -rf /var/azuracast/www/storage && \
    ln -s /var/azuracast/storage /var/azuracast/www/storage

RUN mkdir -p /var/azuracast/www/vendor \
    && chown -R azuracast:azuracast /var/azuracast/www/vendor

USER azuracast

RUN composer install --no-dev --no-ansi --no-autoloader --no-interaction \
    && composer dump-autoload --optimize --classmap-authoritative \
    && composer clear-cache

USER root

# Install Kokoro TTS for human-like AI DJ voices.
# kokoro-v1.0.onnx + voices-v1.0.bin ship the FULL Kokoro v1.0 voice set (~53 speakers).
RUN pip install --break-system-packages kokoro-onnx soundfile \
    && mkdir -p /opt/kokoro \
    && curl -L -o /opt/kokoro/kokoro-v1.0.onnx \
       https://github.com/thewh1teagle/kokoro-onnx/releases/download/model-files-v1.0/kokoro-v1.0.onnx \
    && curl -L -o /opt/kokoro/voices-v1.0.bin \
       https://github.com/thewh1teagle/kokoro-onnx/releases/download/model-files-v1.0/voices-v1.0.bin

COPY --chown=root:root util/docker/kokoro/kokoro_tts.py /opt/kokoro/kokoro_tts.py
RUN chmod +x /opt/kokoro/kokoro_tts.py

ENTRYPOINT ["tini", "--", "/usr/local/bin/my_init"]
CMD ["--no-main-command"]
