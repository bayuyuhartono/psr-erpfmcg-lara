<?php

namespace App\Console\Commands;

use App\Support\Backup\DatabaseBackup;
use App\Support\Backup\Exceptions\BackupFailed;
use App\Support\Deployment\InstallationVersion;
use Illuminate\Console\Command;

/**
 * Applies an update to a client's installation (9.1).
 *
 * Backup first, always. Migrations are numbered and run in order, but a
 * migration that fails halfway through leaves a database nobody can reason
 * about, and this runs on a machine the person supporting it cannot see.
 *
 * The backup is not optional and not a flag. The one time it would be skipped
 * is the one time it was needed.
 */
final class UpdateInstallation extends Command
{
    protected $signature = 'app:update {--pretend : Show what would run without changing anything}';

    protected $description = 'Back up, apply any pending migrations, and report the new schema version';

    public function handle(InstallationVersion $version, DatabaseBackup $backup): int
    {
        $before = $version->describe();

        $this->line(sprintf('Currently on %s, %d migration(s) waiting.', $before['schema'], $before['pending']));

        if ($before['up_to_date'] && ! $this->option('pretend')) {
            $this->info('Nothing to apply.');

            return self::SUCCESS;
        }

        if ($this->option('pretend')) {
            $this->call('migrate', ['--pretend' => true]);

            return self::SUCCESS;
        }

        try {
            $file = $backup->create('before-update');
        } catch (BackupFailed $exception) {
            // Refusing to continue: an update without a way back is not an
            // update, it is a gamble with someone else's business.
            $this->error('Could not take a backup, so nothing was changed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Backed up to %s.', $file['name']));

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error(sprintf(
                'The update failed. The database is as the migrations left it — restore %s before trying again.',
                $file['name'],
            ));

            return self::FAILURE;
        }

        $this->call('optimize:clear');

        // An uploaded logo lives under storage/app/public and is served through
        // this symlink. A release unpacked from the package has no symlink —
        // and a missing one shows as a broken image on the login screen, which
        // reads as a broken installation.
        // `is_dir` rather than `file_exists`: a symlink left dangling by an
        // extractor is the case that needs fixing, and file_exists() reports
        // false for it while the link still occupies the path.
        if (! is_dir(public_path('storage'))) {
            $this->call('storage:link', ['--force' => true]);
        }

        $after = $version->describe();
        $this->info(sprintf('Now on %s, %d migration(s) applied.', $after['schema'], $after['applied']));

        return self::SUCCESS;
    }
}
