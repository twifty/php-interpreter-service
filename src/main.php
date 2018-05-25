<?php

require __DIR__ . "/vendor/autoload.php";

use Amp\Loop;
use Amp\Socket\SocketException;
use Twifty\Server\Server;

$server = new Server();

if (isset($argv[1]) && 'stop' === $argv[1]) {
    $server->close();
    exit(0);
}

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, [$server, 'close']);
    pcntl_signal(SIGINT, [$server, 'close']);
}

try {
    Loop::run(function () use ($server) {
        if (function_exists('pcntl_signal_dispatch')) {
            Loop::repeat(1000, 'pcntl_signal_dispatch');
        }

        $server->listen();
    });
} catch (SocketException $exception) {
    if (!($code = $exception->getCode())) {
        $code = Server::ERROR_FAILURE;
    }

    fwrite(STDERR, $exception."\n");

    exit($code);
}
