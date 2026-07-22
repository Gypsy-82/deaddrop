# PHP-FPM image: minimal, non-root, hardened.
# pdo_sqlite + opcache are compiled from PHP's bundled sources (no extra system libs).
# `fcgi` provides cgi-fcgi, used by the container healthcheck.
FROM php:8.3-fpm-alpine

RUN set -eux; \
    # Runtime libs: fcgi → cgi-fcgi (healthcheck); sqlite-libs → pdo_sqlite links to it.
    apk add --no-cache fcgi sqlite-libs; \
    # Build-only headers to compile pdo_sqlite (bundled SQLite was dropped in PHP 7.4).
    apk add --no-cache --virtual .build-deps sqlite-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_sqlite opcache; \
    apk del .build-deps; \
    # Pre-create the writable data dir owned by www-data (uid 82) BEFORE the named
    # volume mounts, so the volume inherits that ownership and the non-root process
    # can write the SQLite file with zero manual chown.
    mkdir -p /data; \
    chown 82:82 /data

COPY php/conf.d/zz-hardening.ini  /usr/local/etc/php/conf.d/zz-hardening.ini
COPY php/php-fpm.d/www.conf       /usr/local/etc/php-fpm.d/www.conf
COPY php/php-fpm-healthcheck      /usr/local/bin/php-fpm-healthcheck
COPY php/docker-entrypoint.sh     /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/php-fpm-healthcheck /usr/local/bin/docker-entrypoint.sh

# App code (public/, src/, bin/, db/) is bind-mounted read-only at runtime — nothing is baked in.

USER 82:82

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm", "-F"]
