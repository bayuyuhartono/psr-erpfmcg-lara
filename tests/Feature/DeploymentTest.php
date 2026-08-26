<?php

namespace Tests\Feature;

use App\Support\Deployment\InstallationVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_installation_reports_the_schema_it_is_on(): void
    {
        $installed = app(InstallationVersion::class)->describe();

        // The last migration applied is what distinguishes two installations,
        // and what support asks for over the phone.
        $this->assertStringContainsString('2026_', $installed['schema']);
        $this->assertGreaterThan(20, $installed['applied']);
        $this->assertSame(0, $installed['pending']);
        $this->assertTrue($installed['up_to_date']);
    }

    public function test_a_migration_this_database_has_not_run_is_counted(): void
    {
        // What an installation looks like after new code lands and before
        // anyone has applied it.
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        $installed = app(InstallationVersion::class)->describe();

        $this->assertSame(1, $installed['pending']);
        $this->assertFalse($installed['up_to_date']);
    }

    public function test_the_version_command_reports_pending_work_as_a_failure(): void
    {
        $this->artisan('app:version')->assertExitCode(0);

        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        // Non-zero, so a deployment script notices without reading the output.
        $this->artisan('app:version')
            ->expectsOutputToContain('have not been applied')
            ->assertExitCode(1);
    }

    public function test_an_update_with_nothing_to_do_says_so_and_takes_no_backup(): void
    {
        $before = glob(config('backup.path').'/*.sql') ?: [];

        $this->artisan('app:update')
            ->expectsOutputToContain('Nothing to apply')
            ->assertExitCode(0);

        $this->assertSame($before, glob(config('backup.path').'/*.sql') ?: []);
    }

    public function test_pretending_shows_the_work_without_doing_it(): void
    {
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();
        $pending = app(InstallationVersion::class)->pending();

        $this->artisan('app:update', ['--pretend' => true])->assertExitCode(0);

        // Still pending: pretending changed nothing.
        $this->assertSame($pending, app(InstallationVersion::class)->pending());
    }
}
