<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Content\InvalidContent;
use App\Domain\Content\Slug;

/** A site as the owner describes it (docs/forms.md, 13.). */
final readonly class SiteInput
{
    private function __construct(public string $slug, public string $name, public string $origin)
    {
    }

    /** @throws InvalidForm */
    public static function parse(mixed $slug, mixed $name, mixed $origin): self
    {
        try {
            $slug = Slug::check($slug);
        } catch (InvalidContent $e) {
            throw InvalidForm::because($e->getMessage());
        }

        $name = is_string($name) ? trim($name) : '';

        if ($name === '' || mb_strlen($name) > 100) {
            throw InvalidForm::because('Give the site a name of at most 100 characters.');
        }

        $origin = is_string($origin) ? rtrim(trim($origin), '/') : '';

        if (preg_match('#^(?:https://[a-z0-9.-]+\.[a-z]{2,}|http://(?:localhost|127\.0\.0\.1))(?::\d{1,5})?$#', $origin) !== 1) {
            throw InvalidForm::because('The origin must be https://, like https://www.example.com (http only for localhost).');
        }

        return new self($slug, $name, $origin);
    }
}
