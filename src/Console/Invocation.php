<?php

declare(strict_types=1);

namespace Kinetis\Orbitron\Console;

use Kinetis\Console\CommandArguments;

/**
 * The whole invocation surface the Orbitron commands offer: one
 * `--format` option, one bare `--apply` flag, and no positional
 * arguments.
 *
 * CommandArguments cannot enumerate the options it parsed, so an option
 * no command reads is invisible here. That is left alone rather
 * than met with a second argument parser of Orbitron's own: what is
 * checked is what the commands actually consume.
 */
final class Invocation
{
    private const string FORMAT = 'format';
    private const string APPLY = 'apply';

    // Never instantiated — the one method here is static.
    private function __construct() {}

    /**
     * The format this invocation asks for, or null when it asks for one
     * the command does not render: a positional value, a bare
     * `--format` naming nothing, or a format outside $supported.
     *
     * @param non-empty-list<string> $supported the formats the command renders, the first being its default
     */
    public static function format(CommandArguments $arguments, array $supported): ?string
    {
        if ($arguments->all() !== []) {
            return null;
        }

        if (!$arguments->hasOption(self::FORMAT)) {
            return $supported[0];
        }

        // option() answers null for a bare `--format`, which names no
        // format at all, and the written value otherwise — including the
        // empty string for `--format=`.
        $format = $arguments->option(self::FORMAT);

        return $format !== null && in_array($format, $supported, true) ? $format : null;
    }

    /**
     * Whether this invocation asks to mutate the project, or null when
     * `--apply` carries a value. The flag is the whole request, so it
     * takes nothing: `--apply=yes` names an option that does not exist,
     * and `--apply=no` reads as a refusal the flag cannot express.
     */
    public static function apply(CommandArguments $arguments): ?bool
    {
        if (!$arguments->hasOption(self::APPLY)) {
            return false;
        }

        // option() answers null for a bare `--apply` and the written
        // value otherwise, the empty string for `--apply=` included.
        return $arguments->option(self::APPLY) === null ? true : null;
    }
}
