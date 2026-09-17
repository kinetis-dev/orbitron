<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Console;

use JsonException;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Orbitron\Documents;
use Kinetis\Orbitron\ScaffoldMode;
use Kinetis\Runtime\ProjectRoot;

/**
 * `orbitron:scaffold` — the health-endpoint scaffold as one deterministic
 * JSON document.
 *
 * Without `--apply` it is a preview: every precondition is recomputed and
 * the plan is written, with nothing touched on disk. `--apply` is the
 * only mutation request, and it reads the project again rather than
 * trusting the preview that came before it.
 *
 * An adapter over {@see Documents} holding no scaffold policy of its own,
 * so the MCP server reaches the same document without running this
 * command and parsing its output.
 *
 * JSON is the only format, for the same reason as `orbitron:verify`: this
 * document exists to be parsed.
 *
 * `bootstrap: false`: the answer comes from Composer's installed records,
 * the project's own `composer.json` and four directory checks, so neither
 * the package bootstrap chain nor the application's own bootstrap runs.
 */
final readonly class ScaffoldCommand
{
    /** @var non-empty-list<string> */
    private const array FORMATS = ['json'];

    /**
     * $projectRootOverride is a test seam, exactly as it is on
     * `orbitron:verify`: no argument of this command reaches it, and a
     * scaffold is never written to a path a caller named.
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
        'orbitron:scaffold',
        description: 'Previews the Kinetis health-endpoint scaffold as JSON, and writes its two files with --apply',
        bootstrap: false,
    )]
    public function run(CommandArguments $arguments): int
    {
        $apply = Invocation::apply($arguments);

        if (Invocation::format($arguments, self::FORMATS) === null || $apply === null) {
            fwrite(
                $this->errorOutput,
                "orbitron:scaffold takes no arguments, accepts --format=json only, "
                . "and takes --apply as a bare flag.\n",
            );

            return 2;
        }

        // dirname(__DIR__): the same non-proxied fallback branch
        // `orbitron:verify` relies on — see VerifyCommand::run().
        $document = $this->documents->scaffold(
            $this->projectRootOverride ?? ProjectRoot::detect(dirname(__DIR__)),
            $apply ? ScaffoldMode::Apply : ScaffoldMode::Preview,
        );

        fwrite($this->output, $document->toJson());

        // A completed operation that refused or failed is its own
        // outcome, distinct from a launcher failure (1) and a rejected
        // invocation (2): the document was written and is the answer.
        return $document->failed ? 3 : 0;
    }
}
