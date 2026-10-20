<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/** The forms this app receives, by name (docs/forms.md, 7.). */
final readonly class FormDefinitions
{
    /** @var array<string,FormDefinition> */
    private array $forms;

    public function __construct(FormDefinition ...$forms)
    {
        $byName = [];

        foreach ($forms as $form) {
            $byName[$form->name] = $form;
        }

        $this->forms = $byName;
    }

    public function find(string $name): ?FormDefinition
    {
        return $this->forms[$name] ?? null;
    }
}
