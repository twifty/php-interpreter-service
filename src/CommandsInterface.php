<?php

namespace Twifty\Server;

/**
 *
 */
interface CommandsInterface
{
    /**
     * Block command to configure the sub process' working directory.
     *
     * The directory persists amongst CMD_EXECUTE calls.
     *
     * @var integer
     */
    const CMD_SET_CWD              = 240; // 0xF0

    /**
     * Block command to configure the sub process' environment variables.
     *
     * Variables persist amongst CMD_EXECUTE calls.
     *
     * The variables can be passed as either a json encoded object or singly
     * with a name=value format. To unset a previously set value use null.
     *
     * @var integer
     */
    const CMD_SET_ENV              = 241; // 0xF1

    /**
     * Returns all the configured environment variables as a json encoded object.
     *
     * @var integer
     */
    const CMD_GET_ENV              = 242; // 0xF2

    /**
     * Sends a SIGKILL to the sub process.
     *
     * @var integer
     */
    const CMD_PROCESS_KILL         = 243; // 0xF3

    /**
     * Sends a SIGINT to the sub process.
     *
     * @var integer
     */
    const CMD_PROCESS_INTERUPT     = 244; // 0xF4

    /**
     * Block command to send a signal to the sub process.
     *
     * The block should contain a bytes with an ordinal matching the signal.
     *
     * @var integer
     */
    const CMD_PROCESS_SIGNAL       = 245; // 0xF5

    /**
     * Configures the current sub process to run in the background.
     *
     * Output and the exit code will not be sent to client.
     *
     * @var integer
     */
    const CMD_ABORT_OUTPUT         = 246; // 0xF6

    /**
     * Pokes the server.
     *
     * The server will respond with CMD_PROCESS_STDOUT.
     *
     * @var integer
     */
    const CMD_ARE_YOU_THERE        = 247; // 0xF7

    /**
     * Block command Indicating that the following bytes are to be interpreted as a PHP command.
     *
     * @var integer
     */
    const CMD_PROCESS_EXECUTE      = 248; // 0xF8

    /**
     * Block command for writing to the stdin of the sub process.
     *
     * @var integer
     */
    const CMD_PROCESS_WRITE        = 249; // 0xF9

    /**
     * Block command used to send the sub process' stdout back to the client.
     *
     * @var integer
     */
    const CMD_PROCESS_STDOUT       = 250; // 0xFA

    /**
     * Block command used to send the sub process' stderr back to the client.
     *
     * @var integer
     */
    const CMD_PROCESS_STDERR       = 251; // 0xFB

    /**
     * Block command used to send the sub process' exit code back to the client.
     *
     * @var integer
     */
    const CMD_PROCESS_EXITCODE     = 252; // 0xFC

    /**
     * Block command
     *
     * The first byte of a block command is always a CMD_ constant, the block
     * ends with CMD_ESCAPE followed by BLOCK_END. Any double CMD_ESCAPE bytes
     * in the data are to be interpreted as a single (escaped) byte.
     *
     * @var integer
     */
    const BLOCK_BEGIN              = 253; // 0xFD

    /**
     * Ends the block command.
     *
     * @var integer
     */
    const BLOCK_END                = 254; // 0xFE

    /**
     * Once encountered within a string, indicated the next character is a command.
     *
     * If the next character is also 255, It should be interpreted as a single
     * quoted character.
     *
     * The next character can be either a single byte command, or a block
     * command BLOCK_BEGIN . CMD_* . bytes . CMD_ESCAPE . BLOCK_END
     *
     * @var integer
     */
    const CMD_ESCAPE               = 255; // 0xFF

    const COMMAND_NAMES = [
        self::CMD_SET_CWD          => 'CMD_SET_CWD',
        self::CMD_SET_ENV          => 'CMD_SET_ENV',
        self::CMD_GET_ENV          => 'CMD_GET_ENV',
        self::CMD_PROCESS_KILL     => 'CMD_PROCESS_KILL',
        self::CMD_PROCESS_INTERUPT => 'CMD_PROCESS_INTERUPT',
        self::CMD_PROCESS_SIGNAL   => 'CMD_PROCESS_SIGNAL',
        self::CMD_ABORT_OUTPUT     => 'CMD_ABORT_OUTPUT',
        self::CMD_ARE_YOU_THERE    => 'CMD_ARE_YOU_THERE',
        self::CMD_PROCESS_EXECUTE  => 'CMD_PROCESS_EXECUTE',
        self::CMD_PROCESS_WRITE    => 'CMD_PROCESS_WRITE',
        self::CMD_PROCESS_STDOUT   => 'CMD_PROCESS_STDOUT',
        self::CMD_PROCESS_STDERR   => 'CMD_PROCESS_STDERR',
        self::CMD_PROCESS_EXITCODE => 'CMD_PROCESS_EXITCODE',
        self::BLOCK_BEGIN          => 'BLOCK_BEGIN',
        self::BLOCK_END            => 'BLOCK_END',
        self::CMD_ESCAPE           => 'CMD_ESCAPE'
    ];
}
