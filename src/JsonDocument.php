<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

use JsonException;

/**
 * The one JSON encoding every Orbitron document is written in: key and
 * list order exactly as the document was built, slashes and unicode left
 * as written, and a single trailing newline so one CLI invocation puts
 * exactly one document on STDOUT and one MCP tool result carries exactly
 * one document.
 */
final class JsonDocument
{
    // Never instantiated — the one method here is static.
    private function __construct() {}

    /**
     * @param array<string, mixed> $document
     * @throws JsonException
     */
    public static function render(array $document): string
    {
        return json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }
}
