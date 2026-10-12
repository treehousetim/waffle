<?php

declare(strict_types=1);

/**
 * The settings slice: the `settings` key/value table, which renders its own
 * page from presentation metadata stored in each row (docs/settings.md).
 *
 * This file is also the worked example of a slice: one file here, one in
 * src/config/routes/, one group in tests/Pest.php, and nothing shared edited.
 */

use App\Controllers\SettingController;
use App\Libraries\Http\JsonResponder;
use App\Models\SettingRepository;
use App\Models\TypeRepository;

use function DI\factory;

return [
    SettingRepository::class => factory(
        static fn (PDO $pdo, TypeRepository $types): SettingRepository => new SettingRepository($pdo, $types)
    ),

    SettingController::class => factory(
        static fn (JsonResponder $json, SettingRepository $settings): SettingController
            => new SettingController($json, $settings)
    ),
];
