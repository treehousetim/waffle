<?php

declare(strict_types=1);

use ModuleTool\Terminal;

/* Catalogue and module text is shown, never obeyed (docs/module-command.md, 31). */

it('shows control characters instead of sending them to the terminal (31)', function (string $text, string $shown): void {
    expect(Terminal::safe($text))->toBe($shown);
})->with([
    'an escape sequence' => ["Notes\e]0;pwned\x07", 'Notes\x1B]0;pwned\x07'],
    'a carriage return hiding a line' => ["safe\rUNSAFE", 'safe\x0DUNSAFE'],
    'a C1 control' => ["a\u{9B}2J", 'a\u{9B}2J'],
    'a bidi override' => ["abc\u{202E}def", 'abc\u{202E}def'],
    'a zero-width space' => ["a\u{200B}b", 'a\u{200B}b'],
    'a variation selector' => ["a\u{FE0F}", 'a\u{FE0F}'],
    'bytes that are not UTF-8' => ["caf\xE9", 'caf\xE9'],
]);

it('leaves ordinary text, newlines and tabs alone', function (): void {
    expect(Terminal::safe("Short links → café\n\tindented"))->toBe("Short links → café\n\tindented");
});
