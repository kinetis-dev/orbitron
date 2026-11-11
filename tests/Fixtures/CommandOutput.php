<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests\Fixtures;

use Kinetis\Console\Output;
use RuntimeException;

/**
 * An Output over two in-memory streams, colour off, for a command
 * resolved from a scope the test binds it on. Each take reads what was
 * written since the last one.
 */
final class CommandOutput
{
    public readonly Output $output;

    /** @var resource */
    private mixed $stdout;

    /** @var resource */
    private mixed $stderr;

    public function __construct()
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');

        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Could not open in-memory streams.');
        }

        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->output = new Output($stdout, $stderr, false, false);
    }

    public function takeStdout(): string
    {
        return self::take($this->stdout);
    }

    public function takeStderr(): string
    {
        return self::take($this->stderr);
    }

    /**
     * @param resource $stream
     */
    private static function take(mixed $stream): string
    {
        \rewind($stream);
        $written = (string) \stream_get_contents($stream);
        \ftruncate($stream, 0);
        \rewind($stream);

        return $written;
    }
}
