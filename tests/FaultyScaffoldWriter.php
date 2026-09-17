<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use Kinetis\Orbitron\FileScaffoldWriter;
use Kinetis\Orbitron\ScaffoldWriter;

/**
 * The real writer, with one chosen call made to fail.
 *
 * Every call that is not selected goes through to the filesystem, so a
 * short write really is written twice, a rolled-back file really is gone,
 * and a removal that "fails" really does leave the file behind. The
 * counters are 1-based and count calls of that one kind.
 */
final class FaultyScaffoldWriter implements ScaffoldWriter
{
    /** Bytes accepted per write(); null accepts everything offered. */
    public ?int $chunk = null;

    public ?int $failCreateAt = null;
    public ?int $failWriteAt = null;
    public ?int $failFlushAt = null;
    public ?int $failCloseAt = null;

    /** @var list<int> */
    public array $failRemoveAt = [];

    /** @var list<string> */
    public array $removed = [];

    public int $writes = 0;
    public int $openHandles = 0;

    private readonly ScaffoldWriter $writer;

    private int $creates = 0;
    private int $flushes = 0;
    private int $closes = 0;
    private int $removes = 0;

    public function __construct()
    {
        $this->writer = new FileScaffoldWriter();
    }

    #[\Override]
    public function create(string $path): mixed
    {
        if (++$this->creates === $this->failCreateAt) {
            return null;
        }

        $handle = $this->writer->create($path);

        if ($handle !== null) {
            ++$this->openHandles;
        }

        return $handle;
    }

    #[\Override]
    public function write(mixed $handle, string $bytes): ?int
    {
        if (++$this->writes === $this->failWriteAt) {
            return null;
        }

        return $this->writer->write($handle, $this->chunk === null ? $bytes : substr($bytes, 0, $this->chunk));
    }

    #[\Override]
    public function flush(mixed $handle): bool
    {
        return ++$this->flushes === $this->failFlushAt ? false : $this->writer->flush($handle);
    }

    #[\Override]
    public function close(mixed $handle): bool
    {
        $failed = ++$this->closes === $this->failCloseAt;

        // The real handle closes either way: a close this double reports
        // as failed is still a handle the process must not keep.
        $closed = $this->writer->close($handle);
        --$this->openHandles;

        return !$failed && $closed;
    }

    #[\Override]
    public function remove(string $path): bool
    {
        if (in_array(++$this->removes, $this->failRemoveAt, true)) {
            return false;
        }

        $this->removed[] = $path;

        return $this->writer->remove($path);
    }
}
