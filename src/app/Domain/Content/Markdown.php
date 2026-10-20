<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * The rules for a Markdown body (docs/content.md, 9.). This tells staff when
 * they save; it is not the only defence: the site also sanitises the HTML it
 * renders, keeping only plain tags and safe links (Waffle Site's
 * src/core/lib/sanitize.js).
 *
 * Code (fenced blocks and `inline spans`) may show anything, so it is set
 * aside before the checks. Outside it: no HTML tags, comments or processing
 * instructions; no top-level heading, because the page's one <h1> is the
 * record's title; no images until the media library exists; and every link
 * goes to a path on the site, an anchor, an https:// address or a mailto:
 * address.
 */
final class Markdown
{
    public const int MAX = 60_000;

    /**
     * @param bool $headings whether `##` and lower may appear: a block of text
     *                       has none, because headings are their own blocks
     *
     * @throws InvalidContent
     */
    public static function check(mixed $raw, string $what = 'body', bool $headings = true, int $max = self::MAX): string
    {
        $text = ContentText::multiline($raw, $what, $max);
        $prose = self::withoutCode($text);

        if (preg_match('/<(?!(?:https:\/\/|mailto:)[^>\s]*>)[A-Za-z!?\/]/', $prose) === 1) {
            throw InvalidContent::because("The {$what} holds HTML. Write it in Markdown instead.");
        }

        if (preg_match('/^ {0,3}#(?:[ \t]|$)|^ {0,3}=+[ \t]*$/m', $prose) === 1) {
            throw InvalidContent::because("The {$what} holds a top-level heading (# or ===). The title is the page's heading: start sections at ## instead.");
        }

        if (!$headings && preg_match('/^ {0,3}#{1,6}(?:[ \t]|$)|\S[^\n]*\n {0,3}(?:=+|-+)[ \t]*$/m', $prose) === 1) {
            throw InvalidContent::because("The {$what} holds a heading. Headings are blocks of their own.");
        }

        if (preg_match('/!\[[^\]]*\]\(/', $prose) === 1) {
            throw InvalidContent::because("The {$what} holds an image. Images will come from the media library, which is not built yet.");
        }

        foreach (self::destinations($prose) as $destination) {
            if (!self::allowed($destination)) {
                throw InvalidContent::because(
                    "The link to “{$destination}” is not allowed: link to a page on the site (/events), an https:// address or a mailto: address.",
                );
            }
        }

        return $text;
    }

    /** Whether a link may go there: a site path, an anchor, https or mailto. */
    public static function allowed(string $destination): bool
    {
        return preg_match('/^(?:\/(?!\/)|#|https:\/\/[^\s\/]|mailto:[^\s]+@)/i', $destination) === 1;
    }

    private static function withoutCode(string $text): string
    {
        // An escaped character is never markup, and an escaped backtick never
        // opens code: drop escapes first, so `\`<b>\`` is not mistaken for code.
        $text = (string) preg_replace('/\\\\./s', '  ', $text);
        $text = (string) preg_replace('/^ {0,3}(`{3,}|~{3,})[^\n]*\n.*?(?:^ {0,3}\1[^\n]*$|\z)/ms', '', $text);

        // A code span never runs across a blank line: that ends the paragraph.
        return (string) preg_replace('/(`+)(?!`)(?:(?!\n[ \t]*\n).)+?(?<!`)\1(?!`)/s', '', $text);
    }

    /**
     * Where the inline links and reference definitions point.
     *
     * @return list<string>
     */
    private static function destinations(string $prose): array
    {
        preg_match_all('/\]\(\s*<?([^)\s>]*)/', $prose, $inline);
        // Reference definitions, also inside a quotation or a list item.
        preg_match_all('/^(?:[ \t]*(?:>|[-+*]|\d{1,9}[.)])(?=[ \t]))*[ \t]*\[[^\]]+\]:\s*<?([^\s>]+)/m', $prose, $references);

        return [...$inline[1], ...$references[1]];
    }
}
