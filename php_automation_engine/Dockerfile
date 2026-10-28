# PHP Automation Engine – Home Assistant Add-on
# Bewusst auf alpine:3.22 statt auf ein HA-Base-Image gebaut: kein s6-overlay,
# kein bashio -> ein Prozess, den man komplett versteht und debuggen kann.
ARG BUILD_FROM=alpine:3.22
FROM ${BUILD_FROM}

# Der Supervisor uebergibt beim lokalen Build u.a. die App-Version als BUILD_VERSION.
# Damit meldet die Engine in UI und /api/status die echte Add-on-Version.
ARG BUILD_VERSION=dev
ENV PHPENGINE_VERSION=${BUILD_VERSION}

RUN apk add --no-cache \
        bash \
        curl \
        jq \
        tzdata \
        php84 \
        php84-cli \
        php84-curl \
        php84-mbstring \
        php84-openssl \
        php84-pcntl \
        php84-posix \
        php84-sockets \
        php84-iconv \
    && ln -sf /usr/bin/php84 /usr/bin/php \
    && php -v

WORKDIR /app
COPY engine/ /app/
COPY run.sh /run.sh
RUN chmod +x /run.sh

CMD [ "/run.sh" ]
