<?php

namespace App\Support;

use RuntimeException;

/**
 * Treats a `.sql` dump as untrusted text and finds the statements that would
 * escape whatever database you thought you were importing into.
 *
 * **Why this exists.** `DestructiveDatabaseGuard` watches Artisan commands. It
 * saw nothing at all when a backup was imported statement by statement into what
 * was believed to be an isolated scratch database — because the dump itself
 * redirected. `mysqldump --databases` emits three lines before any table:
 *
 * ```
 * /*!40000 DROP DATABASE IF EXISTS `masar_mini_delivery_e2e`* /;
 * CREATE DATABASE /*!32312 IF NOT EXISTS* / `masar_mini_delivery_e2e` ...;
 * USE `masar_mini_delivery_e2e`;
 * ```
 *
 * The first is the dangerous one, and it is the one a reader skims past: it is a
 * `DROP DATABASE` wrapped in a MySQL *executable* comment. `/*! ... * /` is not a
 * comment to MySQL — the digits are a minimum server version, and `40000` means
 * "every server since 4.0". So a dump run with sufficient privileges drops the
 * whole database and recreates it, whatever target the importer believed it had
 * chosen. That is exactly what happened.
 *
 * **Stripping `USE` is not the fix, and this class does not pretend otherwise.**
 * Removing one line leaves the other two, and a dump can name a database in
 * places no line-based filter will reliably catch. The real guarantee is a MySQL
 * account whose grants reach nothing but scratch databases; this class is the
 * cheap check that runs first and refuses the obvious cases loudly.
 */
final class SqlDumpSafety
{
    /**
     * Statements that change which database is being written to, or destroy one.
     *
     * Each pattern tolerates an optional leading executable comment, because
     * that is precisely where the dangerous one hides.
     */
    private const SCOPE_PATTERNS = [
        'DROP DATABASE' => '~^\s*(?:/\*!\d*\s*)?DROP\s+(?:DATABASE|SCHEMA)\b~i',
        'CREATE DATABASE' => '~^\s*(?:/\*!\d*\s*)?CREATE\s+(?:DATABASE|SCHEMA)\b~i',
        'ALTER DATABASE' => '~^\s*(?:/\*!\d*\s*)?ALTER\s+(?:DATABASE|SCHEMA)\b~i',
        'USE' => '~^\s*(?:/\*!\d*\s*)?USE\s+~i',
    ];

    /**
     * Other constructs worth refusing in a dump that is about to be replayed.
     *
     * None of them appear in an ordinary `mysqldump` of tables and rows, so
     * their presence means the file is doing something else and deserves a
     * person's attention before it runs.
     */
    private const DANGEROUS_PATTERNS = [
        'SET GLOBAL' => '~^\s*SET\s+GLOBAL\b~i',
        'GRANT' => '~^\s*GRANT\b~i',
        'REVOKE' => '~^\s*REVOKE\b~i',
        'CREATE USER' => '~^\s*CREATE\s+USER\b~i',
        'LOAD DATA' => '~\bLOAD\s+DATA\b~i',
        'INTO OUTFILE' => '~\bINTO\s+(?:OUTFILE|DUMPFILE)\b~i',
        'SOURCE' => '~^\s*(?:\\\\\.|SOURCE)\s+\S~i',
    ];

    /**
     * Everything in the file that changes scope or looks out of place.
     *
     * @return list<array{line: int, kind: string, statement: string}>
     */
    public static function scan(string $sql): array
    {
        $findings = [];

        foreach (preg_split('/\r?\n/', $sql) ?: [] as $index => $line) {
            foreach (self::SCOPE_PATTERNS + self::DANGEROUS_PATTERNS as $kind => $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $findings[] = [
                        'line' => $index + 1,
                        'kind' => $kind,
                        'statement' => trim(preg_replace('/\s+/', ' ', $line) ?? ''),
                    ];

                    break;
                }
            }
        }

        return $findings;
    }

    /** The database names the file mentions in a scope-changing statement. */
    public static function databasesNamed(string $sql): array
    {
        $names = [];

        foreach (self::scan($sql) as $finding) {
            if (! array_key_exists($finding['kind'], self::SCOPE_PATTERNS)) {
                continue;
            }

            if (preg_match('/`([^`]+)`/', $finding['statement'], $m) === 1) {
                $names[$m[1]] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Refuse a dump that could write outside the database it is meant for.
     *
     * The check is deliberately strict: *any* scope-changing statement is a
     * refusal, even one naming the intended target, because a file that can
     * redirect is a file whose target is not the importer's choice. Sanitise it
     * first and import the result.
     *
     * @throws RuntimeException
     */
    public static function assertSafeToReplay(string $sql, string $intendedDatabase): void
    {
        $findings = self::scan($sql);

        if ($findings === []) {
            return;
        }

        $lines = array_map(
            static fn (array $f): string => "  line {$f['line']} [{$f['kind']}]: ".mb_substr($f['statement'], 0, 100),
            $findings,
        );

        $named = self::databasesNamed($sql);

        throw new RuntimeException(implode("\n", array_merge(
            [
                "Refusing to replay this dump into [{$intendedDatabase}]: it contains statements that change "
                .'which database is written to, or that do more than restore tables and rows.',
                $named === [] ? '' : 'Databases it names: '.implode(', ', $named).'.',
                'Found:',
            ],
            $lines,
            [
                'Strip them with sanitise() and replay the result — and do it with a MySQL account whose grants '
                .'reach nothing but the target, because a line filter is a convenience and the grants are the guarantee.',
            ],
        )));
    }

    /**
     * The same dump with every scope-changing statement removed.
     *
     * Only the four scope statements are stripped. The `DANGEROUS_PATTERNS`
     * above are *not* removed, on purpose: silently deleting a `GRANT` or a
     * `LOAD DATA` would turn a file that needs a person into one that merely
     * runs differently than it says. Those stay, `assertSafeToReplay()` keeps
     * refusing, and somebody decides.
     *
     * @return array{0: string, 1: list<array{line: int, kind: string, statement: string}>}
     */
    public static function sanitise(string $sql): array
    {
        $kept = [];
        $removed = [];

        foreach (preg_split('/\r?\n/', $sql) ?: [] as $index => $line) {
            $matched = null;

            foreach (self::SCOPE_PATTERNS as $kind => $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $matched = $kind;

                    break;
                }
            }

            if ($matched === null) {
                $kept[] = $line;

                continue;
            }

            $removed[] = [
                'line' => $index + 1,
                'kind' => $matched,
                'statement' => trim(preg_replace('/\s+/', ' ', $line) ?? ''),
            ];
        }

        return [implode("\n", $kept), $removed];
    }
}
