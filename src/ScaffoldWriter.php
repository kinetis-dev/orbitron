<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * The exclusive-create write set {@see HealthScaffold} uses, and nothing
 * wider: open a file that must not already exist, push bytes at it,
 * flush it, close it, and remove one it created.
 *
 * It exists so the suite can drive the outcomes a real filesystem will
 * not produce on demand — a short write, a write that makes no progress,
 * a flush or close that fails, a second file that cannot be created, a
 * rollback that cannot remove what it wrote. {@see FileScaffoldWriter}
 * is the implementation every command uses.
 *
 * Each method reports failure as a value. None throws, and none of them
 * takes a mode, a flag, or anything else that would let a caller open a
 * file some other way.
 */
interface ScaffoldWriter
{
    /**
     * Creates $path and opens it for writing, failing when anything
     * already exists there.
     *
     * @return resource|null null when the file could not be created, in
     *         which case this invocation created nothing under $path
     */
    public function create(string $path): mixed;

    /**
     * Writes as much of $bytes as the stream accepts.
     *
     * @param resource $handle
     * @return int<0, max>|null the byte count written, which may be
     *         short, or null when the write failed outright
     */
    public function write(mixed $handle, string $bytes): ?int;

    /**
     * @param resource $handle
     */
    public function flush(mixed $handle): bool;

    /**
     * @param resource $handle
     */
    public function close(mixed $handle): bool;

    public function remove(string $path): bool;
}
