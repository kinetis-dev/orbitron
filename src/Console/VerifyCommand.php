<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Console;

use JsonException;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Orbitron\Documents;
use Kinetis\Runtime\ProjectRoot;

/**
 * `orbitron:verify` — one deterministic JSON answer to one narrow
 * question: is this project's Composer layout the one Orbitron supports?
 *
 * JSON is the only format, for the same reason as `orbitron:inspect`:
 * this document exists to be parsed.
 *
 * An adapter over {@see Documents} holding no verification policy of its
 * own, so the MCP server reaches the same document without running this
 * command.
 *
 * `bootstrap: false`: the answer is read from Composer's installed
 * records and the project's own `composer.json`, so neither the package
 * bootstrap chain nor the application's own bootstrap runs.
 */
final readonly class VerifyCommand
{
    /** @var non-empty-list<string> */
    private const array FORMATS = ['json'];

    /**
     * $projectRootOverride is accepted as an optional constructor
     * parameter for the same testability reason as
     * Kinetis\Runtime\ProjectRoot::detect() itself — verifying a fixture
     * layout instead of whatever root this process happens to run under.
     * It is a test seam, never a client-selected path: no argument of
     * this command reaches it.
     */
    public function __construct(
        private Output $output,
        private Documents $documents = new Documents(),
        private ?string $projectRootOverride = null,
    ) {}

    /**
     * @throws JsonException
     */
    #[Command(
        'orbitron:verify',
        description: 'Verifies that the project\'s Composer layout is the one Orbitron supports, as JSON',
        bootstrap: false,
    )]
    public function run(CommandArguments $arguments): int
    {
        if (Invocation::format($arguments, self::FORMATS) === null) {
            $this->output->error(
                "orbitron:verify takes no arguments and accepts --format=json only.",
            );

            return 2;
        }

        // dirname(__DIR__): this file lives one level deeper than
        // bin/kinetis does (src/Console/ vs bin/), which is what
        // ProjectRoot::detect() expects in its non-proxied fallback
        // branch. An installed project runs through Composer's real
        // bin-proxy, which ignores this argument entirely.
        $document = $this->documents->verify(
            $this->projectRootOverride ?? ProjectRoot::detect(dirname(__DIR__)),
        );

        $this->output->write($document->toJson());

        // A completed verification that found errors is a distinct
        // outcome from a launcher failure (1) and from a rejected
        // invocation (2): the document was written and is the answer.
        return $document->failed ? 3 : 0;
    }
}
