<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * One check of a project's Composer layout: the name it is reported
 * under, what it concluded, and the stable machine code for why.
 *
 * The code is the contract a caller branches on. It names the outcome
 * only — never the file's contents, an exception message, or a path.
 */
final readonly class LayoutCheck
{
    public function __construct(
        public string $name,
        public LayoutState $state,
        public string $code,
    ) {}
}
