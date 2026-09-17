<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

use JsonException;

/**
 * One Orbitron document and whether the operation it reports failed.
 *
 * The two travel together because every adapter needs both and neither
 * can be derived from the other: the CLI turns the failure into exit 3
 * while still writing the document, and MCP turns it into `isError: true`
 * on a result that still carries the same document. A caller that only
 * read the body would have to re-implement the status rules to know which
 * it was looking at.
 */
final readonly class Document
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public array $body,
        public bool $failed,
    ) {}

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return JsonDocument::render($this->body);
    }
}
