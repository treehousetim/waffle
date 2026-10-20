<?php

declare(strict_types=1);

namespace App\Libraries\Forms;

use RuntimeException;

/** No form, site or submission by that name or id (docs/forms.md, 7.). */
final class FormsNotFound extends RuntimeException
{
}
