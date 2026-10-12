<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use Delight\Auth\Auth;
use Delight\Db\PdoDatabase;
use Phinx\Seed\AbstractSeed;

/**
 * The accounts local development needs: one at each level.
 *
 * There is no registration screen — production gets its first owner from
 * FIRST_USERNAME/FIRST_USER_PASSWORD through the CreateInitialOwner migration.
 * That leaves a fresh local database with no way in at all, which is what this
 * fixes.
 *
 *     docker compose exec php vendor/bin/phinx seed:run -s UserSeeder
 *
 * Override the password with SEED_PASSWORD if you want your own. An account
 * that already exists is left alone, so a password you changed survives a
 * re-seed.
 *
 * Refuses to run outside development. A seeder that can create a
 * known-password owner is the last thing that should ever execute against
 * production, and `phinx seed:run` is one careless `-e` away from doing
 * exactly that. The deploy does not ship this directory either.
 */
final class UserSeeder extends AbstractSeed
{
    public const string DEFAULT_PASSWORD = 'local-dev-password';

    /** @var list<array{email:string,name:string,role:Role}> */
    private const array ACCOUNTS = [
        ['email' => 'owner@example.test', 'name' => 'Local Owner', 'role' => Role::Owner],
        ['email' => 'admin@example.test', 'name' => 'Local Administrator', 'role' => Role::Administrator],
        ['email' => 'user@example.test', 'name' => 'Local User', 'role' => Role::User],
    ];

    public function run(): void
    {
        $environment = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '';

        if (!in_array($environment, ['development', 'local', 'testing'], true)) {
            throw new RuntimeException(
                "UserSeeder refuses to run with APP_ENV={$environment}. It creates accounts with a"
                . ' known password and is for local development only.'
            );
        }

        $password = (string) ($_ENV['SEED_PASSWORD'] ?? getenv('SEED_PASSWORD') ?: self::DEFAULT_PASSWORD);

        $pdo = $this->getAdapter()->getConnection();
        // Throttling off: a seeder has no request to rate limit.
        $auth = new Auth(PdoDatabase::fromPdo($pdo), null, null, false);
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');

        echo "\n";

        foreach (self::ACCOUNTS as ['email' => $email, 'name' => $name, 'role' => $role]) {
            $exists->execute([$email]);

            if ($exists->fetchColumn() !== false) {
                echo "  kept     {$email} (already exists)\n";

                continue;
            }

            // Through Delight, so the hash is the library's own. A null
            // callback makes the account verified: there is nothing to confirm
            // against locally, and an unverified account cannot sign in.
            $id = $auth->admin()->createUser($email, $password, $name);
            $auth->admin()->addRoleForUserById($id, $role->mask());

            echo "  created  {$email} ({$role->value})\n";
        }

        echo "\n  Password for any account created above: {$password}\n\n";
    }
}
