<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use DomainException;

/** An account with that email address already exists. */
final class AccountExists extends DomainException
{
}
