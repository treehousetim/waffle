<?php

declare(strict_types=1);

namespace App\Libraries\Content;

use RuntimeException;

/** No record type, record or revision by that name or id (docs/content.md, 13.). */
final class ContentNotFound extends RuntimeException
{
}
