<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * A module's `waffle` constraint: space-separated comparisons, `||` between
 * alternatives (docs/module-command.md). Deliberately small, so it needs no
 * library; anything else is refused rather than guessed at (6).
 */
final class Constraint
{
    private const COMPARISON = '/\A(>=|<=|>|<|=)((?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*)){0,2})\z/';

    /** @param list<list<array{0:string,1:string}>> $alternatives */
    private function __construct(private readonly array $alternatives)
    {
    }

    public static function parse(string $constraint): self
    {
        $alternatives = [];

        foreach (explode('||', $constraint) as $alternative) {
            $comparisons = [];

            foreach (preg_split('/ +/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (!preg_match(self::COMPARISON, $part, $match)) {
                    throw self::unreadable($constraint);
                }

                $comparisons[] = [$match[1], self::full($match[2])];
            }

            if ($comparisons === []) {
                throw self::unreadable($constraint);
            }

            $alternatives[] = $comparisons;
        }

        return new self($alternatives);
    }

    public function matches(string $version): bool
    {
        if (!preg_match('/\A\d+\.\d+\.\d+\z/', $version)) {
            throw new Refusal("'{$version}' is not a Waffle version (X.Y.Z).");
        }

        foreach ($this->alternatives as $comparisons) {
            $all = true;

            foreach ($comparisons as [$operator, $bound]) {
                $all = $all && version_compare($version, $bound, $operator === '=' ? '==' : $operator);
            }

            if ($all) {
                return true;
            }
        }

        return false;
    }

    /** `0.1` is `0.1.0`. */
    private static function full(string $version): string
    {
        return implode('.', array_pad(explode('.', $version), 3, '0'));
    }

    private static function unreadable(string $constraint): Refusal
    {
        return new Refusal("the waffle constraint '{$constraint}' is not one this command can read: space-separated comparisons such as '>=0.1 <1.0' (operators >=, >, <=, <, =), with '||' between alternatives.");
    }
}
