<?php
declare(strict_types=1);

namespace Twifty\Server\Test;

use Amp\Loop;
use Amp\Socket\ServerSocket;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Twifty\Server\CommandsInterface;
use Twifty\Server\Interpreter;

/**
 * Test Case for the Interpreter class.
 *
 * @covers Twifty\Server\Interpreter
 */
class InterpreterTest extends TestCase
{
    /**
     * @var Interpreter
     */
    protected $interpreter;

    /**
     * @var ServerSocket|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $socket;

    /**
     * This method is called before each test.
     */
    protected function setUp ()
    {
        $this->socket = $this->createMock(ServerSocket::class);
        $this->interpreter = new Interpreter($this->socket, true, 1);
    }

    /**
     * This method is called after each test.
     */
    protected function tearDown ()
    {
    }

    /**
     * @covers Twifty\Server\Interpreter::isCommand
     */
    public function testIsCommand ()
    {
        self::assertTrue(Interpreter::isCommand(chr(CommandsInterface::CMD_SET_CWD)));
        self::assertTrue(Interpreter::isCommand(chr(CommandsInterface::CMD_ESCAPE)));
        self::assertTrue(Interpreter::isCommand("\xF0"));
        self::assertTrue(Interpreter::isCommand("\xFF"));
        self::assertFalse(Interpreter::isCommand("\x00"));
        self::assertFalse(Interpreter::isCommand("\xEF"));
        self::assertFalse(Interpreter::isCommand("\xFF\xFF"));
    }

    /**
     * @covers Twifty\Server\Interpreter::isEscape
     */
    public function testIsEscape ()
    {
        self::assertTrue(Interpreter::isEscape(chr(CommandsInterface::CMD_ESCAPE)));
        self::assertTrue(Interpreter::isEscape("\xFF"));
        self::assertFalse(Interpreter::isEscape("\xFF\xFF"));
    }

    public function testProcessWorks ()
    {
        $finder = new PhpExecutableFinder();
        if (null === $binary = $finder->find(false)) {
            $this->markTestSkipped('Failed to find the PHP executable.');
        } else {
            $process = new Process($binary.' -r "echo \'hello world!\';"');
            $process->start();
            $process->wait();

            $this->assertEquals('hello world!', $process->getOutput());
        }
    }

    /**
     * @dataProvider provideRead
     */
    public function testRead (string $input, array $output)
    {
        $this->socket
            ->expects($this->exactly(count($output)))
            ->method('write')
            ->withConsecutive(...array_map(function($entry) {
                return [$this->matchesRegularExpression($entry)];
            }, $output))
        ;

        Loop::run(function () use ($input) {
            $this->interpreter->read($input);
        });

        $this->expectOutputRegex('/.+/');
    }

    /**
     * Provider for {@see testRead}
     *
     * @return array
     */
    public function provideRead (): array
    {
        return [
            'CMD_ARE_YOU_THERE' => [
                static::wrap(CommandsInterface::CMD_ARE_YOU_THERE),
                [
                    static::match(CommandsInterface::CMD_PROCESS_STDOUT, "Poke me again! I dare you!!!\n")
                ]
            ],
            'CMD_PROCESS_EXECUTE' => [
                static::wrap(CommandsInterface::CMD_PROCESS_EXECUTE, "php -r \"echo 'hello world!';\""),
                [
                    static::match(CommandsInterface::CMD_PROCESS_EXECUTE, '\d+'),
                    static::match(CommandsInterface::CMD_PROCESS_STDOUT, "hello world!"),
                    static::match(CommandsInterface::CMD_PROCESS_EXITCODE, "0"),
                ]
            ],
            'CMD_PROCESS_WRITE' => [
                static::wrap(CommandsInterface::CMD_PROCESS_EXECUTE, "php -r \"echo trim(fgets(STDIN));\"").
                static::wrap(CommandsInterface::CMD_PROCESS_WRITE, "hello world!\n"),
                [
                    static::match(CommandsInterface::CMD_PROCESS_EXECUTE, '\d+'),
                    static::match(CommandsInterface::CMD_PROCESS_STDOUT, "hello world!"),
                    static::match(CommandsInterface::CMD_PROCESS_EXITCODE, "0"),
                ]
            ],
            'CMD_PROCESS_STDERR' => [
                static::wrap(CommandsInterface::CMD_PROCESS_EXECUTE, "php -r \"fwrite(STDERR, 'hello world!');\""),
                [
                    static::match(CommandsInterface::CMD_PROCESS_EXECUTE, '\d+'),
                    static::match(CommandsInterface::CMD_PROCESS_STDERR, "hello world!"),
                    static::match(CommandsInterface::CMD_PROCESS_EXITCODE, "0"),
                ]
            ],
            'CMD_PROCESS_KILL' => [
                static::wrap(CommandsInterface::CMD_PROCESS_WRITE, "no newline").
                static::wrap(CommandsInterface::CMD_PROCESS_EXECUTE, "php -r \"echo trim(fgets(STDIN));\"").
                static::wrap(CommandsInterface::CMD_PROCESS_KILL),
                [
                    static::match(CommandsInterface::CMD_PROCESS_EXECUTE, '\d+'),
                    static::match(CommandsInterface::CMD_PROCESS_SIGNAL, strval(SIGKILL)),
                ]
            ],
            'CMD_PROCESS_INTERUPT' => [
                static::wrap(CommandsInterface::CMD_PROCESS_EXECUTE, "php -r \"echo trim(fgets(STDIN));\"").
                static::wrap(CommandsInterface::CMD_PROCESS_INTERUPT),
                [
                    static::match(CommandsInterface::CMD_PROCESS_EXECUTE, '\d+'),
                    static::match(CommandsInterface::CMD_PROCESS_SIGNAL, strval(SIGINT)),
                ]
            ],
            'CMD_PROCESS_SIGNAL' => [
                static::wrap(CommandsInterface::CMD_PROCESS_EXECUTE, "php -r \"echo trim(fgets(STDIN));\"").
                static::wrap(CommandsInterface::CMD_PROCESS_SIGNAL, strval(SIGINT)),
                [
                    static::match(CommandsInterface::CMD_PROCESS_EXECUTE, '\d+'),
                    static::match(CommandsInterface::CMD_PROCESS_SIGNAL, strval(SIGINT)),
                ]
            ],
            'CMD_SET_CWD' => [
                static::wrap(CommandsInterface::CMD_SET_CWD, realpath(__DIR__ . '/..')).
                static::wrap(CommandsInterface::CMD_PROCESS_EXECUTE, "php -r \"echo getcwd();\""),
                [
                    static::match(CommandsInterface::CMD_PROCESS_EXECUTE, '\d+'),
                    static::match(CommandsInterface::CMD_PROCESS_STDOUT, realpath(__DIR__ . '/..'), true),
                    static::match(CommandsInterface::CMD_PROCESS_EXITCODE, "0"),
                ]
            ],
            'CMD_SET_ENV_PAIR_DOUBLE_QUOTES' => [
                static::wrap(CommandsInterface::CMD_SET_ENV, 'TEMP_ENV_VAR = "Hello World!"').
                static::wrap(CommandsInterface::CMD_PROCESS_EXECUTE, "php -r \"echo getenv('TEMP_ENV_VAR');\""),
                [
                    static::match(CommandsInterface::CMD_PROCESS_EXECUTE, '\d+'),
                    static::match(CommandsInterface::CMD_PROCESS_STDOUT, "Hello World!"),
                    static::match(CommandsInterface::CMD_PROCESS_EXITCODE, "0"),
                ]
            ],
            'CMD_SET_ENV_PAIR_SINGLE_QUOTES' => [
                static::wrap(CommandsInterface::CMD_SET_ENV, 'TEMP_ENV_VAR = \'Peter\\\'s World!\'').
                static::wrap(CommandsInterface::CMD_PROCESS_EXECUTE, "php -r \"echo getenv('TEMP_ENV_VAR');\""),
                [
                    static::match(CommandsInterface::CMD_PROCESS_EXECUTE, '\d+'),
                    static::match(CommandsInterface::CMD_PROCESS_STDOUT, "Peter's World!"),
                    static::match(CommandsInterface::CMD_PROCESS_EXITCODE, "0"),
                ]
            ],
            'CMD_SET_ENV_JSON' => [
                static::wrap(CommandsInterface::CMD_SET_ENV, json_encode(['TEMP_ENV_VAR' => "Hello World!"])).
                static::wrap(CommandsInterface::CMD_PROCESS_EXECUTE, "php -r \"echo getenv('TEMP_ENV_VAR');\""),
                [
                    static::match(CommandsInterface::CMD_PROCESS_EXECUTE, '\d+'),
                    static::match(CommandsInterface::CMD_PROCESS_STDOUT, "Hello World!"),
                    static::match(CommandsInterface::CMD_PROCESS_EXITCODE, "0"),
                ]
            ],
            'CMD_SET_GET_UNSET_GET_ENV' => [
                static::wrap(CommandsInterface::CMD_SET_ENV, json_encode(['TEMP_ENV_VAR' => "Hello World!"])).
                static::wrap(CommandsInterface::CMD_GET_ENV).
                static::wrap(CommandsInterface::CMD_SET_ENV, 'TEMP_ENV_VAR=null').
                static::wrap(CommandsInterface::CMD_GET_ENV),
                [
                    static::match(CommandsInterface::CMD_PROCESS_STDOUT, json_encode(['TEMP_ENV_VAR' => "Hello World!"]), true),
                    static::match(CommandsInterface::CMD_PROCESS_STDOUT, json_encode([]), true),
                ]
            ],
            'CMD_ABORT_OUTPUT' => [
                static::wrap(CommandsInterface::CMD_PROCESS_EXECUTE, "php -r \"echo trim(fgets(STDIN));\"").
                static::wrap(CommandsInterface::CMD_ABORT_OUTPUT),
                [
                    static::match(CommandsInterface::CMD_PROCESS_EXECUTE, '\d+'),
                    static::match(CommandsInterface::CMD_ABORT_OUTPUT, '\d+'),
                ]
            ]
        ];
    }

    /**
     * Helper method for creating regular expressions for binary strings
     *
     * @param  int     $command    One of the CMD_ constants
     * @param  string  $expression A sub expression
     * @param  boolean $quote      TRUE if the sub expression should be passed to preg_quote
     *
     * @return strin               The built regular expression
     */
    private static function match (int $command, string $expression, bool $quote = false)
    {
        $escape = chr(CommandsInterface::CMD_ESCAPE);

        if (null === $expression) {
            return '/^'.$escape.chr($command).'$/';
        }

        // IsEqual
        return implode('', [
            '/^',
            $escape,
            chr(CommandsInterface::BLOCK_BEGIN),
            chr($command),
            $quote ? preg_quote($expression, '/') : $expression,
            $escape,
            chr(CommandsInterface::BLOCK_END),
            '$/'
        ]);
    }

    /**
     * Helper method for creating binary strings
     *
     * @param  int                  $command One of the CMD_ constants
     * @param  string|callable|null $data    utf8 data to wrap
     *
     * @return string                        Binary string
     */
    private static function wrap (int $command, ?string $data = null): string
    {
        $escape = chr(CommandsInterface::CMD_ESCAPE);

        if (null === $data) {
            return $escape.chr($command);
        }

        return implode('', [
            $escape,
            chr(CommandsInterface::BLOCK_BEGIN),
            chr($command),
            str_replace($escape, $escape.$escape, $data),
            $escape,
            chr(CommandsInterface::BLOCK_END),
        ]);
    }
}
