<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * Which Waffle version an app is on (docs/module-command.md): Waffle's own
 * semver VERSION, or the "Started from" line the rename writes into an app's
 * CHANGELOG.md, since an app's VERSION is its own release number (32).
 */
final class WaffleVersion
{
    public static function of(string $root): string
    {
        $version = is_file($root . '/VERSION') ? trim((string) file_get_contents($root . '/VERSION')) : '';

        if (preg_match('/\A\d+\.\d+\.\d+\z/', $version)) {
            return $version;
        }

        $changelog = is_file($root . '/CHANGELOG.md') ? (string) file_get_contents($root . '/CHANGELOG.md') : '';

        if (preg_match('#^Started from \[treehousetim/waffle v(\d+\.\d+\.\d+)\]#m', $changelog, $match)) {
            return $match[1];
        }

        throw new Refusal("cannot tell which Waffle version this app is built on: VERSION is not X.Y.Z, and CHANGELOG.md has no line 'Started from [treehousetim/waffle vX.Y.Z](...)'. Add that line with the version the app was started from (32).");
    }
}
