<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use RuntimeException;

/** A webhook delivery that cannot be trusted (docs/mail.md, 13.). */
final class InvalidSignature extends RuntimeException
{
}
