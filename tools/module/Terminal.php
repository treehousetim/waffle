<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * Text from the catalogue or a module is printed, never obeyed: every control
 * character but newline and tab is shown as \x.. or \u{..}, so an escape
 * sequence cannot move the cursor, retitle the window or hide a line (31).
 */
final class Terminal
{
    public static function safe(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return (string) preg_replace_callback('/[^\x09\x0A\x20-\x7E]/', static fn (array $m): string => sprintf('\x%02X', ord($m[0])), $text);
        }

        return (string) preg_replace_callback(
            '/[\x00-\x08\x0B-\x1F\x7F\x{80}-\x{9F}]|\p{Cf}|\p{Zl}|\p{Zp}|[\x{FE00}-\x{FE0F}]|[\x{E0100}-\x{E01EF}]/u',
            static fn (array $m): string => mb_ord($m[0]) < 0x80 ? sprintf('\x%02X', mb_ord($m[0])) : sprintf('\u{%X}', mb_ord($m[0])),
            $text,
        );
    }
}
