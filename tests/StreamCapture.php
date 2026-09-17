<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

/**
 * An in-memory stand-in for STDOUT/STDERR, so a command's exact bytes
 * can be read back.
 */
final class StreamCapture
{
    /** @var resource */
    public readonly mixed $stream;

    public function __construct()
    {
        $stream = fopen('php://memory', 'rb+');

        assert($stream !== false);

        $this->stream = $stream;
    }

    public function contents(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }
}
