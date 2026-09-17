<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * What a scaffold operation concluded.
 *
 * `Refused` is a precondition the project does not meet, decided before
 * anything is written. `Failed` is a write that started and did not
 * finish, after which the invocation removed what it created.
 */
enum ScaffoldStatus: string
{
    case Ready = 'ready';
    case Created = 'created';
    case Refused = 'refused';
    case Failed = 'failed';
}
