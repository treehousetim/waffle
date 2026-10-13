<?php

declare(strict_types=1);

// tools/module.sh's PHP half (docs/module-command.md): no Composer autoloader, so it runs before vendor/ exists.
spl_autoload_register(static fn (string $class) => str_starts_with($class, 'ModuleTool\\') && require __DIR__ . '/' . str_replace('\\', '/', substr($class, 11)) . '.php');
exit(ModuleTool\Cli::run(ModuleTool\Cli::command((string) getcwd(), STDOUT), $argv, STDOUT, STDERR));
