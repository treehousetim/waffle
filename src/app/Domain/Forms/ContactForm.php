<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/**
 * The form the skeleton ships (docs/forms.md): a name, an email address and
 * a message. A project's own forms follow it.
 */
final class ContactForm implements FormFields
{
    public function parse(array $input): array
    {
        $errors = [];
        $fields = [];

        foreach ([
            'name' => fn (): string => FormText::line($input['name'] ?? null, 100),
            'email' => fn (): string => FormText::email($input['email'] ?? null),
            'message' => fn (): string => FormText::paragraphs($input['message'] ?? null, 5000),
        ] as $field => $check) {
            try {
                $fields[$field] = $check();
            } catch (InvalidForm $e) {
                $errors[$field] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw InvalidForm::because('Please check the form.', $errors);
        }

        return $fields;
    }
}
