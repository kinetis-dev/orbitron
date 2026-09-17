<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * {@see ScaffoldWriter} over the local filesystem.
 *
 * `x+b` is the whole safety property of the create: the file is made by
 * this call or not at all, so an existing target — a regular file, a
 * directory, a symlink, or a symlink pointing at nothing — is never
 * opened and never overwritten.
 *
 * The failing calls are suppressed for the same reason the manifest read
 * is: the diagnostic is the value returned to the caller, which reaches
 * the JSON document, not a PHP warning on a STDERR that stays empty.
 */
final readonly class FileScaffoldWriter implements ScaffoldWriter
{
    #[\Override]
    public function create(string $path): mixed
    {
        $handle = @fopen($path, 'x+b');

        return $handle === false ? null : $handle;
    }

    #[\Override]
    public function write(mixed $handle, string $bytes): ?int
    {
        $written = @fwrite($handle, $bytes);

        return $written === false ? null : $written;
    }

    #[\Override]
    public function flush(mixed $handle): bool
    {
        return fflush($handle);
    }

    #[\Override]
    public function close(mixed $handle): bool
    {
        return fclose($handle);
    }

    #[\Override]
    public function remove(string $path): bool
    {
        return @unlink($path);
    }
}
