<?php

namespace App\Console\Commands;

use App\Support\Deployment\InstallationVersion;
use Illuminate\Console\Command;

final class ShowVersion extends Command
{
    protected $signature = 'app:version';

    protected $description = 'What schema version this installation is on, and whether anything is waiting to be applied';

    public function handle(InstallationVersion $version): int
    {
        $installed = $version->describe();

        $this->table(['', ''], [
            ['Schema version', $installed['schema']],
            ['Migrations applied', $installed['applied']],
            ['Waiting to be applied', $installed['pending']],
            ['Update batches', $installed['batches']],
            ['Code commit', $installed['commit'] ?? 'not a git checkout'],
        ]);

        if (! $installed['up_to_date']) {
            $this->warn(sprintf(
                '%d migration(s) have not been applied. Run: php artisan app:update',
                $installed['pending'],
            ));

            return self::FAILURE;
        }

        $this->info('Up to date.');

        return self::SUCCESS;
    }
}
