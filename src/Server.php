<?php

namespace Twifty\Server;

use Amp\Loop;
use Amp\Socket\ServerSocket;
use Amp\Socket\SocketException;
use Twifty\Server\Interpreter;
use Symfony\Component\Process\PhpExecutableFinder;
use function Amp\asyncCall;
use function \Amp\Socket\listen;

class Server
{
    const ERROR_SUCCESS = 0;
    const ERROR_RUNNING = 1;
    const ERROR_FAILURE = 2;

    /**
     * Resolves the PHP executable
     *
     * @return string The command or path required to run PHP
     */
    protected static function getPhpBinary (): string
    {
        $finder = new PhpExecutableFinder();

        if (null === $binary = $finder->find(false)) {
            throw new \RuntimeException('Unable to find the PHP binary.');
        }

        return $binary;
    }

    /**
     * The listening server
     *
     * @var \Amp\Socket\Server
     */
    private $server;

    /**
     * Attached clients
     *
     * @var Interpreter[]
     */
    private $clients = [];

    /**
     * The PHP process path
     *
     * @var string
     */
    private $binary;

    /**
     * The maximum time a process can run, in seconds.
     *
     * @var float|null
     */
    private $timeout;

    /**
     * Constructor.
     */
    public function __construct () {
        $this->host = getenv('PHP_INTERPRETER_HOST') ?: 'localhost';
        $this->port = getenv('PHP_INTERPRETER_PORT') ?: '1337';
        $this->debug = getenv('PHP_INTERPRETER_DEBUG') ?: false;
        $this->binary = getenv('PHP_INTERPRETER_BINARY') ?: static::getPhpBinary();
        $this->timeout = getenv('PHP_INTERPRETER_TIMEOUT') ?: null;

        $this->uri = sprintf('tcp://%s:%s', $this->host, $this->port);
    }

    /**
     * Attempts to begin listening on a socket
     *
     * @throws SocketException If the socket is already in use
     */
    public function listen () {
        asyncCall(function () {
            $lockFile = $this->getLockFile();

            if (file_exists($lockFile)) {
                @unlink($lockFile);
                $retries = 10;

                do {
                    try {
                        $this->server = listen($this->uri);
                    } catch (SocketException $exception) {
                        if (--$retries > 0) {
                            sleep(1);
                            continue;
                        }

                        throw $exception;
                    }
                } while (!$this->server);
            } else {
                $this->server = listen($this->uri);
            }

            // try {
            //     $this->server = listen($this->uri);
            // } catch (SocketException $exception) {
            //     if (file_exists($lockFile)) {
            //         // We need to close the other instance so that it frees the port. Then this
            //         // instance can start listening. We prefer this instance so that the caller
            //         // can monitor stdout and stderr for debug messages.
            //         throw new SocketException('Already running!', self::ERROR_RUNNING, $exception);
            //     }
            //
            //     throw $exception;
            // }

            file_put_contents($lockFile, getmypid());
            printf("Listening on %s %s...\n", $this->server->getAddress(), $this->debug ? '(debug enabled) ' : '');

            $this->poll();

            while ($socket = yield $this->server->accept()) {
                $this->handleClient($socket);
            }

            print("Shutting down ...\n");
            @unlink($lockFile);
        });
    }

    /**
     * Closes this and any other running servers.
     */
    public function close () {
        $lockFile = $this->getLockFile();

        if (!$this->server) {
            if (file_exists($lockFile)) {
                $this->doSignal(SIGTERM);
            }
        } else {
            $this->server->close();
        }

        @unlink($lockFile);
        Loop::stop();
    }

    /**
     * Checks for termination signals or deleted a lock file.
     */
    private function poll () {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        if (!file_exists($this->getLockFile())) {
            $this->close();
        } else {
            Loop::defer(\Closure::fromCallable([$this, 'poll']));
        }
    }

    /**
     * Handles a new connection to the server
     *
     * @param  ServerSocket $socket The client socket
     */
    private function handleClient (ServerSocket $socket) {
        asyncCall(function () use ($socket) {
            $remoteAddr = $socket->getRemoteAddress();

            print "Accepted new client: {$remoteAddr}" . PHP_EOL;

            $this->clients[$remoteAddr] = new Interpreter($socket, $this->binary, $this->debug, $this->timeout);

            while (null !== $chunk = yield $socket->read()) {
                $this->clients[$remoteAddr]->read($chunk);
            }

            unset($this->clients[$remoteAddr]);

            print "Client disconnected: {$remoteAddr}" . PHP_EOL;
        });
    }

    /**
     * Sends a POSIX signal to the process.
     *
     * @param string $signal         A valid POSIX signal (see http://www.php.net/manual/en/pcntl.constants.php)
     * @param bool   $throwException Whether to throw exception in case signal failed
     *
     * @return bool True if the signal was sent successfully, false otherwise
     *
     * @throws LogicException   In case the process is not running
     * @throws RuntimeException In case --enable-sigchild is activated and the process can't be killed
     * @throws RuntimeException In case of failure
     */
    private function doSignal(string $signal, bool $throwException = false): bool {
        if (false === $pid = @file_get_contents($this->getLockFile())) {
            if ($throwException) {
                throw new LogicException('Can not send signal on a non running process.');
            }

            return false;
        }

        if ('\\' === DIRECTORY_SEPARATOR) {
            exec(sprintf('taskkill /F /T /PID %d 2>&1', $pid), $output, $exitCode);
            if ($exitCode) {
                if ($throwException) {
                    throw new RuntimeException(sprintf('Unable to kill the process (%s).', implode(' ', $output)));
                }

                return false;
            }
        } else {
            if (function_exists('posix_kill')) {
                $ok = @posix_kill($pid, $signal);
            } elseif ($ok = proc_open(sprintf('kill -%d %d', $signal, $pid), array(2 => array('pipe', 'w')), $pipes)) {
                $ok = false === fgets($pipes[2]);
            }

            if (!$ok) {
                if ($throwException) {
                    throw new RuntimeException(sprintf('Error while sending signal `%s`.', $signal));
                }

                return false;
            }
        }

        return true;
    }

    /**
     * Returns the path of the lock file
     *
     * @return string
     */
    private function getLockFile (): string {
        return sys_get_temp_dir().'/php-remote-interpreter.lock';
    }
}
