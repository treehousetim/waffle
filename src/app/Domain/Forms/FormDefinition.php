<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/** A form the app receives (docs/forms.md): `form:<name>` in a container fragment. */
final readonly class FormDefinition
{
    public function __construct(
        public string $name,
        public string $label,
        /** What the visitor is told when it is received. */
        public string $thanks,
        public FormFields $fields,
    ) {
    }
}
