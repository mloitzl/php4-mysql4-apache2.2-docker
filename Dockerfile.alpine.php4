FROM alpine AS base-amd64
FROM arm64v8/alpine AS base-arm64

FROM base-${TARGETARCH} AS builder
# FROM alpine AS build

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

# # Build Mysql 4
# ADD mysql-4.1.22.tar.bz2 .

# RUN echo '/* Linuxthreads */' >> /usr/include/pthread.h
# WORKDIR /tmp/mysql-4.1.22
# RUN ./configure --prefix=/usr/local/mysql --build=aarch64-unknown-linux-gnu CXXFLAGS="-std=gnu++98 -fpermissive"
# RUN sed -i "/HAVE_GETHOSTBYADDR_R/d" config.h
# RUN sed -i "/HAVE_GETHOSTBYNAME_R/d" config.h
# RUN make && make install

WORKDIR /tmp

# Linuxtrheads hack explained: https://bugs.mysql.com/bug.php?id=19785
# gnu++98 (error: narrowing conversion):  https://bugs.mysql.com/bug.php?id=19785
# libc6-compat solves the problem of missing libm.so on aarch64

FROM alpine AS final-amd64
FROM arm64v8/alpine AS final-arm64

FROM final-${TARGETARCH} AS final

ARG TARGETARCH

RUN echo "final: $TARGETARCH"
# FROM alpine

# RUN apk add --no-cache libstdc++ imap-dev apr-util bash shadow libpng
RUN apk add --no-cache libstdc++ imap-dev apr-util shadow libpng
# RUN chsh -s /bin/bash

# Setup Mysql to Run
# ADD my.cnf /usr/local/mysql/my.cnf
# RUN addgroup -S mysql && adduser -S mysql -G mysql \
#   && mkdir /usr/local/mysql/var \
#   && chown -R root /usr/local/mysql && chown -R mysql /usr/local/mysql/var && chgrp -R mysql /usr/local/mysql

COPY --from=builder /usr/local/apache2 /usr/local/apache2
# COPY --from=builder /usr/local/mysql /usr/local/mysql
COPY --from=builder /usr/local/lib/php /usr/local/lib/php
# COPY --from=builder /usr/local/expat /usr/local/expat
# COPY --from=builder /usr/local/pcre /usr/local/pcre

ENV PATH="${PATH}:/usr/local/apache2/bin/"

# RUN echo "ServerName hem" >> /usr/local/apache2/conf/httpd.conf
# VOLUME /usr/local/mysql/var /usr/local/apache2/htdocs/
VOLUME /usr/local/apache2/htdocs/
CMD apachectl start \
  && chown daemon -R /usr/local/apache2/htdocs/
#\
# && mysqld_safe --defaults-file=/usr/local/mysql/my.cnf 
