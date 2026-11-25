# syntax=docker/dockerfile:1
#
# smilir in a single container: Apache + PHP (web console and CLI), Python 3
# with venv support, and supercronic (the 1-minute scheduler tick).
#
#   docker build -f docker/Dockerfile -t pipnix .
#
# Usually you use docker-compose.yml at the repository root instead, which adds
# the MySQL service. See docs/DOCKER.md.

FROM php:8.3-apache

ARG SUPERTRONIC_VERSION=v0.2.33

ENV DEBIAN_FRONTEND=noninteractive \
    PIPNIX_APP=/var/www/pipnix/app \
    PIPNIX_APP_DIR=/var/www/pipnix \
    TZ=UTC

# ---------------------------------------------------------------- runtime deps
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        python3 \
        python3-venv \
        python3-pip \
        libzip-dev \
        tini \
        tzdata \
        util-linux \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql zip \
 && ln -sf /usr/bin/python3 /usr/local/bin/python \
 && rm -rf /var/lib/apt/lists/*

# ------------------------------------------------------------------ scheduler
# supercronic runs the crontab in the foreground, logs to stdout and shuts
# down cleanly on SIGTERM (plain `cron -f` does none of that in a container).
RUN set -eux; \
    arch="$(dpkg --print-architecture)"; \
    case "$arch" in \
        amd64|arm64) : ;; \
        *) echo "smilir: unsupported architecture '$arch'" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /usr/local/bin/supercronic \
        "https://github.com/aptible/supercronic/releases/download/${SUPERTRONIC_VERSION}/supercronic-linux-${arch}"; \
    chmod 0755 /usr/local/bin/supercronic; \
    supercronic -version

# ------------------------------------------------------------------ web server
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-pipnix.ini

RUN a2enmod rewrite headers env \
 && printf 'ServerName localhost\n' > /etc/apache2/conf-available/pipnix-servername.conf \
 && a2enconf pipnix-servername

# ----------------------------------------------------------------- application
WORKDIR /var/www/pipnix
COPY . /var/www/pipnix

RUN mkdir -p logs/runs storage dags jobs /venvs \
 && rm -f app/config.php \
 && chown -R www-data:www-data logs storage dags jobs /venvs \
 && chmod 0775 logs storage dags jobs /venvs

# --------------------------------------------------------------------- startup
COPY docker/entrypoint.sh        /usr/local/bin/pipnix-entrypoint
COPY docker/wait-for-db.php      /usr/local/bin/pipnix-wait-db
COPY docker/configure.php        /usr/local/bin/pipnix-configure
COPY docker/supercronic.crontab  /etc/pipnix/crontab

RUN chmod 0755 /usr/local/bin/pipnix-entrypoint \
               /usr/local/bin/pipnix-wait-db \
               /usr/local/bin/pipnix-configure \
 && chmod 0644 /etc/pipnix/crontab \
 && chown -R www-data:www-data /var/www/pipnix

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://127.0.0.1/login >/dev/null \
     && php -r 'require "/var/www/pipnix/app/bootstrap.php"; Db::pdo()->query("SELECT 1");' \
     || exit 1

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/pipnix-entrypoint"]
