FROM scratch
COPY .php-interpreter.phar /php-interpreter.phar
EXPOSE 1337/tcp
ENV PHP_INTERPRETER_HOST localhost
ENV PHP_INTERPRETER_PORT 1337
CMD ["/php-interpreter.phar"]
