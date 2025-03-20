FROM alpine AS base-amd64
FROM arm64v8/alpine AS base-arm64

# there is a high probability that this is not necessary.  I am not sure if the base image is the same for both architectures
FROM base-${TARGETARCH} AS builder

RUN apk add --no-cache build-base ncurses-dev zlib-dev wget flex perl libc6-compat imap-dev apr-util apache2-dev openssl-dev libpng-dev

ARG version=2.2.34
ARG TARGETARCH

WORKDIR /tmp

ADD httpd-${version}.tar.bz2 .

RUN cd httpd-${version} \
  && ./configure --prefix=/usr/local/apache2 --enable-mods-shared=all --enable-deflate --enable-proxy --enable-proxy-balancer --enable-proxy-http \
  && make \
  && make install

# Build PHP 4
ADD php-4.4.9.tar.bz2 .
ADD php.ini /usr/local/apache2/php.ini

WORKDIR /tmp/php-4.4.9
# wget 'http://git.savannah.gnu.org/gitweb/?p=config.git;a=blob_plain;f=config.guess;hb=HEAD' -O config.guess
ADD config.guess .
# wget 'http://git.savannah.gnu.org/gitweb/?p=config.git;a=blob_plain;f=config.sub;hb=HEAD' -O config.sub
ADD config.sub .

ENV CFLAGS="-std=gnu89"
RUN echo "build: $TARGETARCH"

RUN if [ "$TARGETARCH" = "amd64" ]; then \
  CONFIGURE_HOST="--with-apxs2=/usr/local/apache2/bin/apxs --with-mysql --with-gd --enable-force-cgi-redirect --disable-cgi --with-zlib --with-imap --with-config-file-path=/usr/local/apache2 --host=x86_64-unknown-linux-gnu"; \
  elif [ "$TARGETARCH" = "arm64" ]; then \
  CONFIGURE_HOST="--with-apxs2=/usr/local/apache2/bin/apxs --with-mysql --with-gd --enable-force-cgi-redirect --disable-cgi --with-zlib --with-imap --with-config-file-path=/usr/local/apache2 --host=aarch64-unknown-linux-gnu"; \
  else \
  echo "Unsupported architecture: $TARGETARCH" && exit 1; \
  fi \
  && echo "Calling configure with: $CONFIGURE_HOST"  \
  && ./configure $CONFIGURE_HOST
RUN sed -i "/CFLAGS_CLEAN =/c\CFLAGS_CLEAN = -g -O2 -fcommon -std=gnu89" Makefile
RUN sed -i "/CPP =/c\CPP = gcc -E -fcommon" Makefile
RUN make && make install

WORKDIR /tmp

# Setup PHP to Run on Apache
RUN echo 'AddType application/x-httpd-php php' >> /usr/local/apache2/conf/httpd.conf \
  && sed -i 's/DirectoryIndex index.html/DirectoryIndex index.php index.html/' /usr/local/apache2/conf/httpd.conf


FROM alpine AS final-amd64
FROM arm64v8/alpine AS final-arm64

FROM final-${TARGETARCH} AS final

ARG TARGETARCH

RUN echo "final: $TARGETARCH"
RUN apk add --no-cache libstdc++ imap-dev apr-util shadow libpng

COPY --from=builder /usr/local/apache2 /usr/local/apache2
COPY --from=builder /usr/local/lib/php /usr/local/lib/php

ENV PATH="${PATH}:/usr/local/apache2/bin/"

CMD apachectl start \
  && chown daemon -R /usr/local/apache2/htdocs/
