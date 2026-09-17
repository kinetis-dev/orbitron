<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Console;

use JsonException;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Orbitron\Documents;
use Kinetis\Orbitron\JsonDocument;

/**
 * `orbitron:context` — the Orbitron context document, rendered whole to
 * STDOUT in one write.
 *
 * An adapter over {@see Documents} holding no document policy of its own,
 * so the MCP server reaches the same text without running this command.
 *
 * `bootstrap: false`: the document is built from Composer's
 * installed-package records alone, so neither the package bootstrap chain
 * nor the application's own bootstrap runs.
 */
final readonly class ContextCommand
{
    /** @var non-empty-list<string> markdown first, because it is the default */
    private const array FORMATS = ['markdown', 'json'];

    /**
     * @param resource $output
     * @param resource $errorOutput
     */
    public function __construct(
        private Documents $documents = new Documents(),
        private mixed $output = STDOUT,
        private mixed $errorOutput = STDERR,
    ) {}

    /**
     * @throws JsonException
     */
    #[Command(
        'orbitron:context',
        description: 'Prints the Orbitron development-harness context document for a coding agent',
        bootstrap: false,
    )]
    public function run(CommandArguments $arguments): int
    {
        $format = Invocation::format($arguments, self::FORMATS);

        if ($format === null) {
            fwrite(
                $this->errorOutput,
                "orbitron:context takes no arguments and accepts --format=markdown (the default) or --format=json.\n",
            );

            return 2;
        }

        fwrite($this->output, $format === 'json'
            ? JsonDocument::render($this->documents->contextBody())
            : $this->documents->context());

        return 0;
    }
}
