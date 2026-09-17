<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * What one scaffold operation concluded, in the form every adapter
 * renders: the operation, its status, the stable codes for why, and the
 * project-relative files a failed rollback may have left behind.
 *
 * A code names an outcome only — never a file's contents, an exception
 * message, or an absolute path. The two targets are not carried here
 * because they are fixed: {@see HealthScaffold::TARGETS} is the one
 * authority for them.
 */
final readonly class ScaffoldOutcome
{
    /**
     * @param list<string> $codes the stable diagnostic codes, in the one
     *        order they are reported in; never empty
     * @param list<string> $remainingFiles the project-relative files this
     *        invocation created and could not remove again; empty
     *        whenever the pre-apply state is intact
     */
    public function __construct(
        public ScaffoldMode $mode,
        public ScaffoldStatus $status,
        public array $codes,
        public array $remainingFiles,
    ) {}

    /**
     * Whether the preview is ready or the apply completed — the two
     * outcomes that are not a refusal or a failed write.
     */
    public function succeeded(): bool
    {
        return $this->status === ScaffoldStatus::Ready || $this->status === ScaffoldStatus::Created;
    }
}
