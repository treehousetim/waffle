<?php

declare(strict_types=1);

use App\Models\HealthRepository;
use Tests\TestCase;

it('names the newest migration the database has run', function (): void {
    $files = glob(dirname(__DIR__, 3) . '/src/app/phinx/migrations/*.php') ?: [];
    $newest = max(array_map(static fn (string $file): string => substr(basename($file), 0, 14), $files));

    expect((new HealthRepository(TestCase::pdo()))->latestMigration())->toBe($newest);
});
