<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use RuntimeException;

/**
 * A throwaway project root with — or deliberately without — a
 * `composer.json`, so a layout read runs against a real directory
 * instead of a mocked filesystem.
 */
final class ProjectFixture
{
    public readonly string $root;

    public function __construct(?string $manifest)
    {
        $root = sys_get_temp_dir() . '/orbitron-layout-' . bin2hex(random_bytes(8));

        if (!mkdir($root, 0o700, true)) {
            throw new RuntimeException("Could not create the fixture root {$root}.");
        }

        $this->root = $root;

        if ($manifest !== null) {
            file_put_contents($this->manifestPath(), $manifest);
        }
    }

    public function manifestPath(): string
    {
        return $this->root . '/composer.json';
    }

    public function remove(): void
    {
        $manifest = $this->manifestPath();

        if (is_file($manifest)) {
            chmod($manifest, 0o600);
            unlink($manifest);
        }

        rmdir($this->root);
    }
}
