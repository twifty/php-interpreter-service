FROM scratch
COPY ./php-interpreter.phar /php-interpreter.phar
COPY ./php-interpreter.phar.pubkey /php-interpreter.phar.pubkey
EXPOSE 1337/tcp
ENV PHP_INTERPRETER_HOST localhost
ENV PHP_INTERPRETER_PORT 1337

RUN ["/usr/bin/php", "-v"]
# RUN ["chmod", "+x", "/php-interpreter.phar"]
# CMD ["/php-interpreter.phar"]
# ENTRYPOINT /php-interpreter.phar
