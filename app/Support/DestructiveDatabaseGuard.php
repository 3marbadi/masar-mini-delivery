<?php

namespace App\Support;

use Illuminate\Console\Events\CommandStarting;
use RuntimeException;

/**
 * Refuses a database-destroying command unless the database it would destroy
 * has been named in advance.
 *
 * **Why this exists.** During the stage 3 corrective review `php artisan
 * migrate:fresh` was run without naming a database. The command does not
 * default to the test database and it does not ask: it resolves
 * `config('database.connections.mysql.database')`, which in this checkout is
 * `masar_mini_delivery_e2e`, and it dropped every table in it. Roughly a
 * hundred rows of end-to-end fixture data were lost, and nothing in the command
 * line said which database was about to be rebuilt.
 *
 * The lesson is not "be more careful with flags". It is that a destructive
 * command whose target is implicit will eventually be aimed at the wrong thing,
 * and that the target should have to be *stated* rather than inherited. So the
 * guard inverts the default: every destructive command is refused unless the
 * resolved database is on an allowlist, and the refusal names both the command
 * and the database so the mistake is visible before it happens rather than
 * afterwards.
 *
 * **What it deliberately is not.** It is not a permission system and it does not
 * know about production — this application has no credentials for the live
 * server, and a guard that claimed to protect it would be a guard nobody could
 * trust. It is a local foot-gun catch, and its whole value is that the
 * allowlist is short and boring: the test database, and names that announce
 * themselves as disposable.
 *
 * **And it watches Artisan only, which is a real limit rather than a caveat.**
 * It saw nothing when a backup was imported into what was believed to be an
 * isolated scratch database: no Artisan command ran, and the dump redirected
 * itself with a `DROP DATABASE` wrapped in `/*!40000 ... *​/` — a comment only
 * to a reader. {@see SqlDumpSafety} covers that path, and neither class covers
 * `mysql`, Workbench or any other client. The only guarantee that holds across
 * all of them is a MySQL account whose grants reach nothing but the database it
 * is meant for; these two classes are the cheap checks that run first.
 *
 * The test database is on the list because `RefreshDatabase` runs `migrate:fresh`
 * through Artisan on every suite, and a guard that blocked the suite would be
 * removed within the day.
 */
final class DestructiveDatabaseGuard
{
    /**
     * Commands that drop or truncate every table they can reach.
     *
     * `migrate:rollback` is absent on purpose: it is reversible by design, its
     * scope is one batch, and this project's own migrations refuse to roll back
     * when doing so would lose state that cannot be reconstructed.
     */
    private const DESTRUCTIVE = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ];

    /**
     * Databases whose contents may be destroyed without further ceremony.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        $configured = config('database.destructive_allowlist');

        return is_array($configured) ? array_values(array_filter(array_map('strval', $configured))) : [];
    }

    /**
     * A name that announces itself as disposable.
     *
     * The escape hatch, and shaped so it cannot be taken by accident: a scratch
     * database has to be *created* with this prefix, which is a deliberate act,
     * and no database anyone cares about is going to be called
     * `masar_md_scratch_…`.
     */
    public static function isScratchName(string $database): bool
    {
        return preg_match('/^masar_md_scratch_[a-z0-9_]+$/', $database) === 1;
    }

    public static function permits(string $database): bool
    {
        return in_array($database, self::allowed(), true) || self::isScratchName($database);
    }

    /**
     * Decide, for one command invocation, whether it may proceed.
     *
     * The connection is resolved the same way the migrator resolves it — through
     * the configuration, honouring `--database` when the command carries one —
     * so the name checked here is the name that would actually be dropped. A
     * guard that checked the default while the command used an override would be
     * worse than none.
     *
     * @throws RuntimeException when the resolved database is not destroyable
     */
    public static function assertCommandIsSafe(CommandStarting $event): void
    {
        $command = (string) $event->command;

        if (! in_array($command, self::DESTRUCTIVE, true)) {
            return;
        }

        $connection = $event->input->hasParameterOption('--database')
            ? (string) $event->input->getParameterOption('--database')
            : (string) config('database.default');

        $database = (string) config("database.connections.{$connection}.database");

        if (self::permits($database)) {
            return;
        }

        throw new RuntimeException(implode(' ', [
            "Refusing to run [{$command}] against database [{$database}] on connection [{$connection}].",
            'This command drops every table it can reach, and that database is not on the destructive allowlist.',
            'Allowed:', (self::allowed() === [] ? '(none)' : implode(', ', self::allowed())),
            '— plus any database named masar_md_scratch_*.',
            'If you meant a disposable database, create one with that prefix and pass --database or DB_DATABASE explicitly.',
        ]));
    }
}
