<?php

declare(strict_types=1);

namespace App\Libraries\Forms;

use RuntimeException;

/** One visitor sent too many submissions in a short time (docs/forms.md, 4.). */
final class TooManySubmissions extends RuntimeException
{
}
