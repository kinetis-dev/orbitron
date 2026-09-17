<?php

declare(strict_types=1);

namespace Kinetis\Orbitron;

/**
 * Which of the two scaffold operations produced an outcome. Preview
 * reads; apply is the only one that may write.
 */
enum ScaffoldMode: string
{
    case Preview = 'preview';
    case Apply = 'apply';
}
