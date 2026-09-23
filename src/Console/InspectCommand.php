<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Console;

use JsonException;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Orbitron\Documents;
use Kinetis\Runtime\ProjectRoot;

/**
 * `orbitron:inspect` — the physical project root and the installed
 * `kinetis/*` inventory as one JSON document on STDOUT, for an agent that
 * confirms which checkout it reads and that project's Kinetis versions
 * before reading a guide.
 *
 * No launcher stands between this command and its caller, so it reports
 * the physical project root as `checkoutRoot` too: the identity of this
 * process's own filesystem view.
 *
 * JSON is the only format: this document exists to be parsed. Omitting
 * `--format` and writing `--format=json` are the same invocation.
 *
 * An adapter over {@see Documents}, and `bootstrap: false` for the same
 * reason as `orbitron:context`.
 */
final readonly class InspectCommand
{
    /** @var non-empty-list<string> */
    private const array FORMATS = ['json'];

    /**
     * $projectRootOverride is a test seam for the same reason as
     * {@see VerifyCommand}'s, never a client-selected path: no argument of
     * this command reaches it.
     *
     * @param resource $output
     * @param resource $errorOutput
     */
    public function __construct(
        private Documents $documents = new Documents(),
        private ?string $projectRootOverride = null,
        private mixed $output = STDOUT,
        private mixed $errorOutput = STDERR,
    ) {}

    /**
     * @throws JsonException
     */
    #[Command(
        'orbitron:inspect',
        description: 'Prints the physical project root and the installed kinetis/* package versions as JSON',
        bootstrap: false,
    )]
    public function run(CommandArguments $arguments): int
    {
        if (Invocation::format($arguments, self::FORMATS) === null) {
            fwrite(
                $this->errorOutput,
                "orbitron:inspect takes no arguments and accepts --format=json only.\n",
            );

            return 2;
        }

        // dirname(__DIR__), for the reason VerifyCommand states.
        $document = $this->documents->inspect(
            $this->projectRootOverride ?? ProjectRoot::detect(dirname(__DIR__)),
        );

        fwrite($this->output, $document->toJson());

        return 0;
    }
}
