<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use Delight\Auth\Auth;
use Delight\Db\PdoDatabase;
use Phinx\Migration\AbstractMigration;

/**
 * The first owner account, so a fresh production database can be logged into.
 *
 * WHY THIS IS A MIGRATION AND NOT A SEED
 *
 * `UserSeeder` refuses to run outside development, and the deploy rsync does
 * not ship `src/app/phinx/seeds/` at all — both deliberate, because that seeder
 * creates a verified owner whose password is printed in its own source. So on a
 * fresh production database there is no way in. Migrations are the only thing
 * that runs there automatically, which makes this the bootstrap hook.
 *
 * THE CREDENTIALS COME FROM DOPPLER, NOT FROM THIS FILE
 *
 * `FIRST_USERNAME` (the email signed in with) and `FIRST_USER_PASSWORD` are injected
 * the same way every other secret is, so the password is never committed and
 * never written to disk. A password in this file would sit in git history
 * forever, readable by anyone with the repository long after it had been
 * changed.
 *
 * With either value unset the migration does nothing and says so. That is what
 * makes it safe everywhere: the test suite runs migrations on every run, local
 * development has no reason to want this account, and **both keys can be
 * deleted from Doppler once the account exists** — this migration has already
 * been recorded in `phinxlog` by then, and would skip on its own regardless.
 *
 * Users are created through Delight\Auth rather than by INSERT so the stored
 * hash is the one the library itself writes: PasswordHash::from() pre-hashes
 * with a peppered HMAC-SHA-512 and tags the result with a `$pa01` prefix, which
 * is what lets a password longer than bcrypt's 72 bytes count in full. A plain
 * `password_hash()` row would still verify, but would silently ignore
 * everything after the 72nd byte.
 */
final class CreateInitialOwner extends AbstractMigration
{
    /** Display name. The sign-in identity is the email in FIRST_USERNAME. */
    private const string DISPLAY_NAME = 'Owner';

    public function up(): void
    {
        $email = trim($this->env('FIRST_USERNAME'));
        $password = $this->env('FIRST_USER_PASSWORD');

        if ($email === '' || $password === '') {
            $this->output->writeln(
                '  <comment>skipped</comment> no initial owner: set FIRST_USERNAME and'
                . ' FIRST_USER_PASSWORD in Doppler and deploy again'
            );

            return;
        }

        $pdo = $this->getAdapter()->getConnection();

        // Idempotent by email rather than relying on phinxlog alone: the account
        // may already exist because someone created it by hand, or because this
        // migration was rolled back and re-applied.
        $lookup = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $lookup->execute([$email]);

        if ($lookup->fetchColumn() !== false) {
            $this->output->writeln("  <comment>skipped</comment> {$email} (already exists)");

            return;
        }

        // The fourth argument disables throttling, which would otherwise want a
        // request context this has no business having during a migration.
        $auth = new Auth(PdoDatabase::fromPdo($pdo), null, null, false);

        // A null callback makes the account verified immediately: there is no
        // mail transport here, and an unverified owner could never sign in.
        $id = $auth->admin()->createUser($email, $password, self::DISPLAY_NAME);
        $auth->admin()->addRoleForUserById($id, Role::Owner->mask());

        $this->output->writeln("  <info>created</info> {$email} (" . Role::Owner->value . ')');
        $this->output->writeln(
            '  <comment>next</comment> change this password at first login, then delete'
            . ' FIRST_USER_PASSWORD from Doppler'
        );
    }

    public function down(): void
    {
        // Deliberately does nothing.
        //
        // A rollback that deleted the account would take its audit-log rows and
        // any work attributed to it with it, and would lock everyone out of a
        // running system to undo a migration whose only job was to let people
        // in. Removing the owner is a decision for a human with a reason, not a
        // side effect of `phinx rollback`.
        $this->output->writeln(
            '  <comment>kept</comment> the initial owner — remove it by hand if that is really what you want'
        );
    }

    private function env(string $name): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            $value = $_ENV[$name] ?? '';
        }

        return (string) $value;
    }
}
