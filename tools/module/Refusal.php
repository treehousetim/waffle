<?php

declare(strict_types=1);

namespace ModuleTool;

use RuntimeException;

/**
 * Why the module command stopped. The message is for the owner, in plain
 * words, and is printed as it is.
 */
final class Refusal extends RuntimeException
{
}
