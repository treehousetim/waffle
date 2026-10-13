<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * What Contents found in a module: the reasons to refuse it, and what the
 * owner is shown before building (14).
 */
final class Scan
{
    /**
     * @param list<string> $refusals
     * @param list<string> $files      every file, relative, sorted
     * @param list<string> $binaries   files holding a NUL byte, not read as text
     * @param list<array{file:string,line:int,text:string}> $comments
     */
    public function __construct(
        public readonly array $refusals,
        public readonly array $files,
        public readonly array $binaries,
        public readonly array $comments,
    ) {
    }
}
