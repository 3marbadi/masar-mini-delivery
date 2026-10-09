<?php

namespace Tests\Feature;

use App\Support\DestructiveDatabaseGuard;
use Illuminate\Console\Events\CommandStarting;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * The guard that exists because of a real incident.
 *
 * During the stage 3 corrective review `php artisan migrate:fresh` was run
 * without naming a database. It does not default to the test database: it
 * resolves whatever the configuration says, which in this checkout is the
 * end-to-end database, and it dropped every table in it. About a hundred rows of
 * fixture data went with them.
 *
 * These cases pin the two halves of the fix — that an unnamed destructive
 * command is refused, and that the test database is still usable, since
 * `RefreshDatabase` runs `migrate:fresh` through Artisan on every suite and a
 * guard which blocked that would be deleted within the day.
 *
 * No test here runs a destructive command. They exercise the decision, not the
 * demolition.
 */
class DestructiveDatabaseGuardTest extends TestCase
{
    public function test_the_test_database_is_destroyable_because_the_suite_needs_it(): void
    {
        $this->assertTrue(DestructiveDatabaseGuard::permits('masar_mini_delivery_testing'));
        $this->assertContains('masar_mini_delivery_testing', DestructiveDatabaseGuard::allowed());
    }

    public function test_the_end_to_end_and_development_databases_are_not_destroyable(): void
    {
        // The exact database the incident destroyed. Listing it would undo the
        // fix, so its absence is the point.
        $this->assertFalse(DestructiveDatabaseGuard::permits('masar_mini_delivery_e2e'));
        $this->assertFalse(DestructiveDatabaseGuard::permits('masar_mini_delivery'));
        $this->assertFalse(DestructiveDatabaseGuard::permits('masar'));
        $this->assertFalse(DestructiveDatabaseGuard::permits('masar_test'));
    }

    public function test_only_an_unmistakably_disposable_name_is_accepted_by_pattern(): void
    {
        $this->assertTrue(DestructiveDatabaseGuard::isScratchName('masar_md_scratch_rollback'));
        $this->assertTrue(DestructiveDatabaseGuard::isScratchName('masar_md_scratch_a1'));

        // A prefix has to be taken deliberately; near misses are not.
        $this->assertFalse(DestructiveDatabaseGuard::isScratchName('masar_md_scratch'));
        $this->assertFalse(DestructiveDatabaseGuard::isScratchName('scratch_masar_md'));
        $this->assertFalse(DestructiveDatabaseGuard::isScratchName('masar_mini_delivery_scratch_x'));
        $this->assertFalse(DestructiveDatabaseGuard::isScratchName('MASAR_MD_SCRATCH_X'));
    }

    public function test_a_destructive_command_against_the_default_connection_is_refused(): void
    {
        config(['database.default' => 'probe']);
        config(['database.connections.probe' => ['driver' => 'mysql', 'database' => 'masar_mini_delivery_e2e']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to run \[migrate:fresh\].*masar_mini_delivery_e2e/s');

        DestructiveDatabaseGuard::assertCommandIsSafe($this->commandStarting('migrate:fresh'));
    }

    public function test_the_guard_honours_an_explicit_database_override(): void
    {
        config(['database.default' => 'probe']);
        config([
            'database.connections.probe' => ['driver' => 'mysql', 'database' => 'masar_mini_delivery_testing'],
            'database.connections.danger' => ['driver' => 'mysql', 'database' => 'masar_mini_delivery_e2e'],
        ]);

        // A guard that checked the default while the command used an override
        // would be worse than none: it would pass while the demolition went
        // somewhere else.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/masar_mini_delivery_e2e/');

        DestructiveDatabaseGuard::assertCommandIsSafe(
            $this->commandStarting('migrate:fresh', ['--database' => 'danger']),
        );
    }

    public function test_an_allowed_database_passes_and_a_harmless_command_is_not_examined(): void
    {
        config(['database.default' => 'probe']);
        config(['database.connections.probe' => ['driver' => 'mysql', 'database' => 'masar_mini_delivery_testing']]);

        DestructiveDatabaseGuard::assertCommandIsSafe($this->commandStarting('migrate:fresh'));

        // `migrate` and `migrate:rollback` are not destructive: one is additive
        // and the other is reversible by design and scoped to a batch — and
        // this project's own migrations refuse to roll back when that would
        // lose state nothing can reconstruct.
        config(['database.connections.probe.database' => 'masar_mini_delivery_e2e']);
        DestructiveDatabaseGuard::assertCommandIsSafe($this->commandStarting('migrate'));
        DestructiveDatabaseGuard::assertCommandIsSafe($this->commandStarting('migrate:rollback'));

        $this->addToAssertionCount(1);
    }

    /** @param array<string, mixed> $parameters */
    private function commandStarting(string $command, array $parameters = []): CommandStarting
    {
        $definition = new InputDefinition([
            new InputOption('database', null, InputOption::VALUE_OPTIONAL),
        ]);

        return new CommandStarting($command, new ArrayInput($parameters, $definition), new NullOutput());
    }
}
