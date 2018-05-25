<?php

namespace Twifty\Server;

use Amp\Socket\ServerSocket;
use Amp\Loop;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class Interpreter implements CommandsInterface
{
    const EXPECT_ESCAPE = 0;
    const EXPECT_COMMAND = 1;
    const EXPECT_MULTIBYTE = 2;
    const EXPECT_MULTIBYTE_COMMAND = 3;
    const EXPECT_END_OF_RANGE = 4;

    /**
     * The clients socket connection
     *
     * @var ServerSocket
     */
    private $socket;

    /**
     * Flag to toggle the sending of error messages to the client.
     *
     * @var bool
     */
    private $debug;

    /**
     * The maximum time a process can run, in seconds.
     *
     * @var float|null
     */
    private $timeout;

    /**
     * The time the current process start.
     *
     * @var float
     */
    private $startTime;

    /**
     * The PHP process path
     *
     * @var string
     */
    private $binary;

    /**
     * Unconsumed data sent from client split into lines.
     *
     * @var string[]
     */
    private $commands = [];

    /**
     * Partial last line data
     *
     * @var string
     */
    private $currLine = '';

    /**
     * The current state of the parser.
     *
     * @var \stdClass
     */
    private $parser;

    /**
     * The currently running process.
     *
     * @var Process
     */
    private $process;

    /**
     * The stdin stream of the sub process
     *
     * @var InputStream
     */
    private $input;

    /**
     * Processes which are running in the background.
     *
     * @var Process[]
     */
    private $background;

    /**
     * The directory in which the process should be executed.
     *
     * @var string
     */
    private $cwd;

    /**
     * Environment variables to be passed to the PHP process.
     *
     * @var string[]
     */
    private $env;

    /**
     * Checks if the given byte represents a command.
     *
     * @param  string $char A binary string of length 1
     *
     * @return bool         TRUE if is a command
     */
    public static function isCommand (string $char): bool {
        return 1 === strlen($char) && isset(self::COMMAND_NAMES[ord($char)]);
    }

    /**
     * Checks if the given byte is the IAC byte.
     *
     * @param  string $char A binary string of length 1
     *
     * @return bool         TRUE if is the IAC byte
     */
    public static function isEscape (string $char): bool {
        return 1 === strlen($char) && self::CMD_ESCAPE === ord($char);
    }

    /**
     * Constructor.
     *
     * @param ServerSocket $socket  The socket to which the client is connected
     * @param string       $binary  The path to the PHP binary
     * @param boolean      $debug   If TRUE, will print debug messages to stdout
     * @param float        $timeout The maximum number of seconds a sub process may run
     */
    public function __construct (ServerSocket $socket, string $binary = null, bool $debug = false, ?float $timeout = null) {
        $this->socket = $socket;
        $this->binary = $binary;
        $this->debug = $debug;
        $this->timeout = $timeout;
        $this->process = null;
        $this->background = [];

        $this->cwd = null;
        $this->env = [];

        $this->parser = new \stdClass();
        $this->parser->expects = self::EXPECT_ESCAPE;
        $this->parser->command = null;
        $this->parser->data = '';
        $this->parser->read = '';
    }

    /**
     * Consumes data from the socket
     *
     * @param  string $data Raw binary data
     */
    public function read (string $data) {
        $this->debug($data, 'server received');

        foreach (str_split($data) as $char) {
            switch ($this->parser->expects) {
                case self::EXPECT_ESCAPE:
                    if (static::isEscape($char)) {
                        if ('' !== $this->parser->read) {
                            $this->debug($this->parser->read, 'skipped');
                            $this->parser->read = '';
                        }
                        $this->parser->expects = self::EXPECT_COMMAND;
                    }
                    break;

                case self::EXPECT_COMMAND:
                    if (!static::isCommand($char)) {
                        $this->parser->expects = self::EXPECT_ESCAPE;
                    } elseif (self::BLOCK_BEGIN === ord($char)) {
                        $this->parser->expects = self::EXPECT_MULTIBYTE_COMMAND;
                    } else {
                        $this->handleCommand(ord($char));
                        $this->parser->expects = self::EXPECT_ESCAPE;
                    }
                    break;

                case self::EXPECT_MULTIBYTE_COMMAND:
                    if (static::isCommand($char)) {
                        $this->parser->command = ord($char);
                        $this->parser->data = '';
                        $this->parser->expects = self::EXPECT_MULTIBYTE;
                    } else {
                        $this->parser->expects = self::EXPECT_ESCAPE;
                    }
                    break;

                case self::EXPECT_MULTIBYTE:
                    if (static::isEscape($char)) {
                        $this->parser->expects = self::EXPECT_END_OF_RANGE;
                    } else {
                        $this->parser->data .= $char;
                    }
                    break;

                case self::EXPECT_END_OF_RANGE:
                    if (static::isEscape($char)) {
                        $this->parser->data .= $char;
                        $this->parser->expects = self::EXPECT_MULTIBYTE;
                    } elseif (self::BLOCK_END === ord($char)) {
                        $this->handleCommand($this->parser->command, $this->parser->data);

                        $this->parser->command = null;
                        $this->parser->data = '';
                        $this->parser->read = '';

                        $this->parser->expects = self::EXPECT_ESCAPE;

                        // Prevent the char from being added to read
                        continue;
                    } else {
                        $this->parser->expects = self::EXPECT_ESCAPE;
                    }
                    break;
            }

            $this->parser->read .= $char;
        }
    }

    /**
     * Writes a binary string back to the socket
     *
     * @param  int    $command The response command
     * @param  string $data    The data to be written
     */
    private function write (int $command, string $data) {
        $escape = chr(self::CMD_ESCAPE);

        $rawData = implode('', [
            $escape,
            chr(self::BLOCK_BEGIN),
            chr($command),
            str_replace($escape, $escape.$escape, $data),
            $escape,
            chr(self::BLOCK_END)
        ]);

        $this->debug($rawData, 'server sending');
        $this->socket->write($rawData);
    }

    /**
     * Executes a command passed over the socket.
     *
     * @param  int         $command One of the CMD_ constants
     * @param  string|null $data    (optional) Any data associated with the command
     */
    private function handleCommand (int $command, string $data = null): void {
        switch ($command) {
            case self::CMD_PROCESS_EXECUTE:
                if (isset($this->process)) {
                    $this->write(self::CMD_PROCESS_STDERR, 'A process is already running!');
                } else {
                    try {
                        $this->createProcess(trim($data));
                    } catch (\Exception $ex) {
                        $this->write(self::CMD_PROCESS_STDERR, (string)$ex);
                    }
                    $this->write(self::CMD_PROCESS_EXECUTE, strval($this->process->getPid()));
                }
                break;

            case self::CMD_PROCESS_WRITE:
                if (!isset($this->input)) {
                    $this->input = new InputStream();
                }
                $this->input->write($data);
                break;

            case self::CMD_PROCESS_KILL:
                if (isset($this->process)) {
                    $this->process->signal(SIGKILL);
                }
                break;

            case self::CMD_PROCESS_INTERUPT:
                if (isset($this->process)) {
                    $this->process->signal(SIGINT);
                }
                break;

            case self::CMD_PROCESS_SIGNAL:
                if (isset($this->process)) {
                    $this->process->signal(intval($data));
                }
                break;

            case self::CMD_ABORT_OUTPUT:
                if (isset($this->process)) {
                    $pid = $this->process->getPid();

                    $this->background[$pid] = $this->process;

                    $this->input->close();

                    $this->input = null;
                    $this->process = null;

                    $this->write(self::CMD_ABORT_OUTPUT, $pid);
                }
                break;

            case self::CMD_ARE_YOU_THERE:
                $this->write(self::CMD_PROCESS_STDOUT, 'Poke me again! I dare you!!!'."\n");
                break;

            case self::CMD_SET_CWD:
                if (!is_string($data) || !is_dir($data)) {
                    $this->write(self::CMD_PROCESS_STDERR, 'The directory "'.$data.'" doesn\'t exist!');
                } else {
                    $this->cwd = $data;
                    $this->write(self::CMD_SET_CWD, $data);
                }
                break;

            case self::CMD_SET_ENV:
                $all = true;
                $vars = null;

                if (is_string($data)) {
                    if ('{' !== $data[0]) {
                        $all = false;
                        $pair = array_map('trim', explode('=', $data, 2));
                        if (2 === count($pair)) {
                            $vars = [];
                            if (null === $pair[1] || 'null' === strtolower($pair[1])) {
                                unset($this->env[$pair[0]]);
                            } else if (preg_match('/^"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"$/s', $pair[1])) {
                                $vars[$pair[0]] = str_replace('\\"', '"', substr($pair[1], 1, -1));
                            } else if (preg_match("/^'[^'\\\\]*(?:\\\\.[^'\\\\]*)*'$/s", $pair[1])) {
                                $vars[$pair[0]] = str_replace("\\'", "'", substr($pair[1], 1, -1));
                            } else {
                                $vars[$pair[0]] = $pair[1];
                            }
                        }
                    } else {
                        $vars = json_decode($data, true);
                    }
                }

                if (null !== $vars) {
                    if ($all) {
                        $this->env = $vars;
                    } else {
                        $this->env = array_merge($this->env, $vars);
                    }
                    $this->write(self::CMD_SET_ENV, json_encode($this->env, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT));
                } else {
                    $this->write(self::CMD_PROCESS_STDERR, 'Malformed env variable "'.$data.'"!');
                }
                break;

            case self::CMD_GET_ENV:
                $data = json_encode($this->env, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
                $this->write(self::CMD_PROCESS_STDOUT, $data);
                break;

            default:
                $this->debug('Invalid Command ('.(self::COMMAND_NAMES[$command] ?? $command).')', 'error');
                break;
        }
    }

    /**
     * Creates and starts a PHP process.
     *
     * @param  string  $parameters The PHP command line
     *
     * @return Process
     */
    private function createProcess (string $commandLine): Process {
        if (isset($this->process)) {
            throw new \RuntimeException('A process is already running.');
        }

        $length = strlen($this->binary);

        if (0 === strncmp($commandLine, 'php ', 4) && 'php' !== $this->binary) {
            $commandLine = $this->binary.substr($commandLine, 3);
        }

        $this->debug("Running Command \"$commandLine\"");

        $this->startTime = microtime(true);
        $this->process = new Process($commandLine, $this->cwd, $this->env);

        if (null !== $this->timeout) {
            $this->process->setTimeout($this->timeout);
        }

        if (!isset($this->input)) {
            $this->input = new InputStream();
        }

        $this->process->setInput($this->input);
        $this->process->start();

        Loop::defer(\Closure::fromCallable([$this, 'tick']));

        return $this->process;
    }

    /**
     * Checks the state of the running and background processes.
     */
    private function tick () {
        if ($this->process) {
            if ('' !== $stdout = $this->process->getIncrementalOutput()) {
                $this->write(self::CMD_PROCESS_STDOUT, $stdout);
            }

            if ('' !== $stderr = $this->process->getIncrementalErrorOutput()) {
                $this->write(self::CMD_PROCESS_STDERR, $stderr);
            }

            if ($this->process->isRunning() && null !== $this->timeout) {
                if ($this->timeout < microtime(true) - $this->startTime) {
                    $this->process->signal(SIGKILL);
                }
            }

            if (!$this->process->isRunning()) {
                if ($this->process->hasBeenSignaled()) {
                    $this->write(self::CMD_PROCESS_SIGNAL, $this->process->getTermSignal());
                } else {
                    $this->write(self::CMD_PROCESS_EXITCODE, $this->process->getExitCode());
                }

                $this->input->close();

                $this->input = null;
                $this->process = null;
            }
        }

        $this->background = array_filter($this->background, function($process) {
            return $process->isRunning();
        });

        if ($this->process || !empty($this->background)) {
            Loop::defer(\Closure::fromCallable([$this, 'tick']));
        }
    }

    /**
     * Prints a message to the console if debug is enabled
     *
     * @param  string|string[] $message A byte array or message to display
     * @param  string          $inset   The type of debug message
     */
    private function debug (string $message, string $inset = 'debug') {
        static $special = [
            '\a' => '\\a',
            '\e' => '\\e',
            '\f' => '\\f',
            '\t' => '\\t',
            '\n' => '\\n',
            '\r' => '\\n'
        ];

        if ($this->debug) {
            print('['.$inset.'] '.preg_replace_callback('/[\x00-\x1F\x7F-\xFF]/', function ($matches) use ($special) {
                $code = ord($matches[0]);

                if (isset(self::COMMAND_NAMES[$code])) {
                    return '['.self::COMMAND_NAMES[$code].']';
                }

                return $special[$matches[0]] ?? '\\x'.bin2hex($matches[0]);
            }, $message)."\r\n");
        }
    }
}
