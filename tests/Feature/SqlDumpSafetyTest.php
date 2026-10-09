<?php

namespace Tests\Feature;

use App\Support\SqlDumpSafety;
use RuntimeException;
use Tests\TestCase;

/**
 * The check that would have stopped the second incident.
 *
 * A backup was imported, statement by statement, into what was believed to be an
 * isolated scratch database. `DestructiveDatabaseGuard` saw nothing: no Artisan
 * command ran. The dump redirected itself — `mysqldump --databases` writes a
 * `DROP DATABASE` wrapped in `/*!40000 ... * /`, a *comment* only to a reader,
 * and MySQL executed it along with the `CREATE DATABASE` and `USE` that follow.
 *
 * These cases pin the detection against the real file, so the specific line that
 * caused the damage is a test fixture rather than a memory.
 *
 * Nothing here connects to a database.
 */
class SqlDumpSafetyTest extends TestCase
{
    private const BACKUP = 'D:/masar-db-backups/20261006T231326Z/masar_mini_delivery_e2e.sql';

    public function test_a_drop_database_hidden_in_an_executable_comment_is_detected(): void
    {
        // The exact shape mysqldump emits, and the one a reader skims past.
        $dump = <<<'SQL'
        -- MySQL dump 10.13
        /*!40000 DROP DATABASE IF EXISTS `masar_mini_delivery_e2e`*/;
        CREATE TABLE `t` (`id` int);
        SQL;

        $findings = SqlDumpSafety::scan($dump);

        $this->assertCount(1, $findings);
        $this->assertSame('DROP DATABASE', $findings[0]['kind']);
        $this->assertSame(2, $findings[0]['line']);
    }

    public function test_all_four_scope_statements_are_detected_bare_and_commented(): void
    {
        foreach ([
            'USE `other`;' => 'USE',
            '/*!40000 USE `other`*/;' => 'USE',
            'CREATE DATABASE `other`;' => 'CREATE DATABASE',
            'CREATE SCHEMA `other`;' => 'CREATE DATABASE',
            'DROP DATABASE `other`;' => 'DROP DATABASE',
            '/*!40000 DROP SCHEMA IF EXISTS `other`*/;' => 'DROP DATABASE',
            'ALTER DATABASE `other` CHARACTER SET utf8mb4;' => 'ALTER DATABASE',
        ] as $statement => $expectedKind) {
            $findings = SqlDumpSafety::scan($statement);

            $this->assertCount(1, $findings, "missed [{$statement}]");
            $this->assertSame($expectedKind, $findings[0]['kind'], "misclassified [{$statement}]");
        }
    }

    public function test_an_ordinary_table_dump_raises_nothing(): void
    {
        $dump = <<<'SQL'
        /*!40101 SET @saved_cs_client = @@character_set_client */;
        DROP TABLE IF EXISTS `customers`;
        CREATE TABLE `customers` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`));
        INSERT INTO `customers` VALUES (1,'x');
        SQL;

        $this->assertSame([], SqlDumpSafety::scan($dump));
        SqlDumpSafety::assertSafeToReplay($dump, 'masar_md_scratch_x');

        $this->addToAssertionCount(1);
    }

    public function test_replaying_a_redirecting_dump_is_refused_and_the_message_names_the_cause(): void
    {
        $dump = "/*!40000 DROP DATABASE IF EXISTS `masar_mini_delivery_e2e`*/;\nUSE `masar_mini_delivery_e2e`;\n";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DROP DATABASE/');
        $this->expectExceptionMessageMatches('/masar_mini_delivery_e2e/');

        SqlDumpSafety::assertSafeToReplay($dump, 'masar_md_scratch_recovery');
    }

    public function test_a_dump_naming_even_the_intended_database_is_still_refused(): void
    {
        // A file that *can* redirect is a file whose target is not the
        // importer's choice, so naming the right database is not reassurance.
        $this->expectException(RuntimeException::class);

        SqlDumpSafety::assertSafeToReplay(
            "USE `masar_md_scratch_recovery`;\nINSERT INTO `t` VALUES (1);\n",
            'masar_md_scratch_recovery',
        );
    }

    public function test_sanitising_removes_only_the_scope_statements(): void
    {
        $dump = "/*!40000 DROP DATABASE IF EXISTS `a`*/;\nCREATE DATABASE `a`;\nUSE `a`;\nINSERT INTO `t` VALUES (1);\nGRANT ALL ON *.* TO 'x';\n";

        [$clean, $removed] = SqlDumpSafety::sanitise($dump);

        $this->assertCount(3, $removed);
        $this->assertStringNotContainsString('DROP DATABASE', $clean);
        $this->assertStringNotContainsString('CREATE DATABASE', $clean);
        $this->assertStringNotContainsString('USE `a`', $clean);
        $this->assertStringContainsString('INSERT INTO `t`', $clean);

        // The GRANT is deliberately left in place: silently deleting it would
        // turn a file that needs a person into one that merely runs differently
        // than it reads. It stays, and the assertion keeps refusing.
        $this->assertStringContainsString('GRANT ALL', $clean);
        $this->expectException(RuntimeException::class);
        SqlDumpSafety::assertSafeToReplay($clean, 'masar_md_scratch_x');
    }

    public function test_other_dangerous_constructs_are_reported(): void
    {
        foreach ([
            "SET GLOBAL read_only = 0;" => 'SET GLOBAL',
            "GRANT ALL ON *.* TO 'x'@'%';" => 'GRANT',
            "REVOKE ALL ON *.* FROM 'x'@'%';" => 'REVOKE',
            "CREATE USER 'x'@'%';" => 'CREATE USER',
            "SELECT 1 INTO OUTFILE '/tmp/x';" => 'INTO OUTFILE',
        ] as $statement => $kind) {
            $findings = SqlDumpSafety::scan($statement);

            $this->assertNotEmpty($findings, "missed [{$statement}]");
            $this->assertSame($kind, $findings[0]['kind']);
        }
    }

    public function test_the_real_backup_file_is_detected_as_redirecting(): void
    {
        if (! is_readable(self::BACKUP)) {
            $this->markTestSkipped('the 2026-10-06 backup is not present on this machine');
        }

        $dump = file_get_contents(self::BACKUP);

        $kinds = array_column(SqlDumpSafety::scan($dump), 'kind');

        // The three lines that caused the incident, pinned as a fixture.
        $this->assertContains('DROP DATABASE', $kinds);
        $this->assertContains('CREATE DATABASE', $kinds);
        $this->assertContains('USE', $kinds);
        $this->assertSame(['masar_mini_delivery_e2e'], SqlDumpSafety::databasesNamed($dump));

        // And once sanitised it is replayable anywhere.
        [$clean, $removed] = SqlDumpSafety::sanitise($dump);
        $this->assertCount(3, $removed);
        SqlDumpSafety::assertSafeToReplay($clean, 'masar_md_scratch_recovery');

        // The tables and rows survive the sanitising untouched.
        $this->assertSame(
            substr_count($dump, "\nINSERT INTO "),
            substr_count($clean, "\nINSERT INTO "),
        );
        $this->assertSame(
            substr_count($dump, "\nCREATE TABLE "),
            substr_count($clean, "\nCREATE TABLE "),
        );
    }
}
