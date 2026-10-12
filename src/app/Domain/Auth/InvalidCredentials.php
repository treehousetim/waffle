<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Wrong address, wrong password, or an attempt a callback refused.
 *
 * These are deliberately one failure and not three: telling them apart tells an
 * attacker which addresses exist.
 */
final class InvalidCredentials extends AuthenticationFailure
{
}
