<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Tests;

use JsonException;
use RuntimeException;

/**
 * A throwaway project root the scaffold can be run against: the admitted
 * Composer manifest and the four fixed directories, each of which a test
 * removes, replaces or points elsewhere to reach one refusal.
 *
 * The PSR-4 prefix is unique per fixture, so two applied scaffolds in one
 * PHPUnit process declare two different `HealthController` classes rather
 * than colliding on one name.
 */
final class ScaffoldProject
{
    /** @var list<string> */
    public const array DIRECTORIES = ['src', 'src/Http', 'tests', 'tests/Http'];

    public readonly string $root;
    public readonly string $production;
    public readonly string $test;

    /**
     * @throws JsonException
     */
    public function __construct(bool $manifest = true, bool $directories = true)
    {
        $suffix = 'N' . bin2hex(random_bytes(6));
        $root = sys_get_temp_dir() . '/orbitron-scaffold-' . $suffix;

        if (!mkdir($root, 0o700, true)) {
            throw new RuntimeException("Could not create the fixture root {$root}.");
        }

        $this->root = $root;
        $this->production = "ScaffoldFixture\\{$suffix}\\";
        $this->test = "ScaffoldFixture\\{$suffix}\\Tests\\";

        if ($manifest) {
            $this->writeManifest((string) json_encode([
                'autoload' => ['psr-4' => [$this->production => 'src/']],
                'autoload-dev' => ['psr-4' => [$this->test => 'tests/']],
            ], JSON_THROW_ON_ERROR));
        }

        if ($directories) {
            foreach (self::DIRECTORIES as $directory) {
                if (!mkdir($this->path($directory), 0o700, true)) {
                    throw new RuntimeException("Could not create the fixture directory {$directory}.");
                }
            }
        }
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . $relative;
    }

    public function writeManifest(string $contents): void
    {
        file_put_contents($this->path('composer.json'), $contents);
    }

    public function contents(string $relative): string
    {
        return (string) file_get_contents($this->path($relative));
    }

    /**
     * Every path under the root, relative and sorted — what a test reads
     * to prove that a preview changed nothing, or that a rollback put the
     * project back as it was.
     *
     * @return list<string>
     */
    public function tree(): array
    {
        $paths = [];

        self::collect($this->root, '', $paths);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Registers the fixture's own PSR-4 prefixes for the rest of the
     * process, so a generated class loads the way the consumer project's
     * own autoloader would load it.
     */
    public function autoload(): void
    {
        $production = $this->production;
        $test = $this->test;
        $root = $this->root;

        spl_autoload_register(static function (string $class) use ($production, $test, $root): void {
            // The test prefix is the longer of the two and sits under the
            // production one, so it is matched first.
            foreach (['tests' => $test, 'src' => $production] as $directory => $prefix) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
                $file = "{$root}/{$directory}/{$relative}.php";

                if (is_file($file)) {
                    require_once $file;
                }

                return;
            }
        });
    }

    public function remove(): void
    {
        self::delete($this->root);
    }

    /**
     * @param list<string> $paths
     * @param-out list<string> $paths
     */
    private static function collect(string $absolute, string $relative, array &$paths): void
    {
        foreach (scandir($absolute) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $relative === '' ? $entry : "{$relative}/{$entry}";
            $paths[] = $child;

            // A symlink is recorded and never descended: one pointing
            // outside the fixture would otherwise list a directory this
            // does not own.
            if (!is_link("{$absolute}/{$entry}") && is_dir("{$absolute}/{$entry}")) {
                self::collect("{$absolute}/{$entry}", $child, $paths);
            }
        }
    }

    private static function delete(string $path): void
    {
        // is_link() first, for the same reason: a symlinked directory is
        // unlinked, never walked into.
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::delete("{$path}/{$entry}");
            }
        }

        @rmdir($path);
    }
}
