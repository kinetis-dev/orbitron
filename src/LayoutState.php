<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * What one layout check concluded. `Skip` is not a weaker `Error`: it is
 * the state of a check that was never performed, because the manifest it
 * depends on could not be read. A check never guesses.
 */
enum LayoutState: string
{
    case Pass = 'pass';
    case Error = 'error';
    case Skip = 'skip';
}
