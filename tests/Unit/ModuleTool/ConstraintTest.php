<?php

declare(strict_types=1);

use ModuleTool\Constraint;
use ModuleTool\Refusal;

/*
 * The `waffle` constraint grammar (docs/module-command.md, "How it is built"):
 * space-separated comparisons, `||` between alternatives. Numbers cite that
 * document's failure list.
 */

it('matches versions against a range (5)', function (string $constraint, string $version, bool $matches): void {
    expect(Constraint::parse($constraint)->matches($version))->toBe($matches);
})->with([
    'inside' => ['>=0.1 <1.0', '0.1.0', true],
    'inside, later' => ['>=0.1 <1.0', '0.9.12', true],
    'below' => ['>=0.1 <1.0', '0.0.9', false],
    'at the upper bound' => ['>=0.1 <1.0', '1.0.0', false],
    'greater than' => ['>0.1.0', '0.1.1', true],
    'not greater than' => ['>0.1.0', '0.1.0', false],
    'at most' => ['<=2', '2.0.0', true],
    'above at most' => ['<=2', '2.0.1', false],
    'exact' => ['=0.2', '0.2.0', true],
    'not exact' => ['=0.2', '0.2.1', false],
    'first alternative' => ['>=0.1 <0.2 || >=1.0', '0.1.5', true],
    'second alternative' => ['>=0.1 <0.2 || >=1.0', '1.3.0', true],
    'neither alternative' => ['>=0.1 <0.2 || >=1.0', '0.5.0', false],
    'extra spaces' => ['  >=0.1    <1.0  ', '0.5.0', true],
]);

it('refuses anything outside the grammar (6)', function (string $constraint): void {
    expect(fn () => Constraint::parse($constraint))->toThrow(Refusal::class);
})->with([
    'empty' => [''],
    'caret' => ['^0.1'],
    'tilde' => ['~0.1'],
    'wildcard' => ['0.1.*'],
    'bare version' => ['0.1'],
    'comma' => ['>=0.1,<1.0'],
    'space after operator' => ['>= 0.1'],
    'four parts' => ['>=0.1.0.0'],
    'pre-release' => ['>=0.1.0-beta'],
    'not equal' => ['!=0.1'],
    'empty alternative' => ['>=0.1 ||'],
    'single bar' => ['>=0.1 | <1.0'],
    'leading zero' => ['>=01.0'],
]);

it('names the constraint it could not read (6)', function (): void {
    expect(fn () => Constraint::parse('^0.1'))->toThrow(Refusal::class, "'^0.1'");
});

it('refuses to compare against a version that is not X.Y.Z (32)', function (): void {
    expect(fn () => Constraint::parse('>=0.1')->matches('0.1'))->toThrow(Refusal::class);
});
