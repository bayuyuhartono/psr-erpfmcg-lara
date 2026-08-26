<?php

namespace App\Console\Commands;

use App\Support\Deployment\InstallationVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Builds an update a client can install without an internet connection (9.2).
 *
 * The PRD says some clients have no external access, which rules out composer
 * install on their server and rules out pulling from anywhere. So the package
 * carries `vendor/` already built, and the install step is: unpack, run
 * `app:update`, done.
 *
 * `.env`, `storage/` and the database are deliberately not in it. An update
 * package that overwrote a client's configuration or their uploaded files
 * would be a very effective way of losing their data.
 */
final class PackageUpdate extends Command
{
    protected $signature = 'app:package {--out= : Where to write the package}';

    protected $description = 'Build an offline update package for a client without internet access';

    /** Everything a client's installation owns, and must keep. */
    private const EXCLUDED = [
        '.env', '.env.example', '.git', '.github', 'node_modules',
        'storage', 'tests', '.phpunit.cache', 'docs',
    ];

    public function handle(InstallationVersion $version): int
    {
        $installed = $version->describe();
        $name = sprintf('psr-erpfmcg-%s.zip', now()->format('Ymd-His'));
        $path = ($this->option('out') ?: storage_path('app/packages')).DIRECTORY_SEPARATOR.$name;

        File::ensureDirectoryExists(dirname($path));

        if (! is_dir(base_path('vendor'))) {
            $this->error('No vendor/ directory. Run composer install first — the point of this package is that the client cannot.');

            return self::FAILURE;
        }

        // The built front end is generated, so it is not in git — and a client
        // with no internet cannot run npm. A package without it installs a
        // panel with no styling at all.
        if (! is_file(public_path('build/manifest.json')) || ! is_dir(public_path('css/filament'))) {
            $this->error('The front end is not built. Run these first, or the client gets an unstyled panel:');
            $this->line('    php artisan filament:assets');
            $this->line('    npm ci && npm run build');

            return self::FAILURE;
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('Could not create '.$path);

            return self::FAILURE;
        }

        $zip->addFromString('MANIFEST.txt', $this->manifest($installed));
        $zip->addFromString('INSTALL.txt', $this->instructions());

        $files = 0;

        foreach ($this->files() as $file) {
            $relative = ltrim(str_replace(base_path(), '', $file), DIRECTORY_SEPARATOR);
            $zip->addFile($file, 'app/'.$relative);
            $files++;
        }

        $zip->close();

        $this->info(sprintf('%s — %d files, %.1f MB.', $name, $files, filesize($path) / 1048576));
        $this->line('Ships with vendor/ built. The client unpacks it and runs php artisan app:update.');

        return self::SUCCESS;
    }

    /** @return iterable<string> */
    private function files(): iterable
    {
        $process = new Process(['git', 'ls-files'], base_path(), timeout: 60);
        $process->run();

        // A git checkout knows what belongs to the project; a build from a
        // package does not, so fall back to walking it.
        $paths = $process->isSuccessful()
            ? array_filter(explode("\n", trim($process->getOutput())))
            : $this->walk(base_path());

        foreach ($paths as $relative) {
            $full = str_starts_with($relative, '/') ? $relative : base_path($relative);

            if (! is_file($full) || $this->isExcluded($relative)) {
                continue;
            }

            yield $full;
        }

        // None of these are in git, and all of them are needed on a machine
        // that cannot run composer or npm.
        foreach (['vendor', 'public/build', 'public/css', 'public/js', 'public/fonts'] as $generated) {
            foreach ($this->walk(base_path($generated)) as $file) {
                yield $file;
            }
        }
    }

    /** @return list<string> */
    private function walk(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        return array_map(
            fn (\SplFileInfo $file): string => $file->getPathname(),
            iterator_to_array(File::allFiles($directory)),
        );
    }

    private function isExcluded(string $relative): bool
    {
        foreach (self::EXCLUDED as $exclude) {
            if ($relative === $exclude || str_starts_with($relative, $exclude.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $installed */
    private function manifest(array $installed): string
    {
        return implode("\n", [
            'PSR ERP FMCG — update package',
            'Built:            '.now()->toDateTimeString(),
            'Schema version:   '.$installed['schema'],
            'Migrations:       '.$installed['applied'],
            'Built from commit:'.($installed['commit'] ?? 'unknown'),
            '',
            'Contains the application, its vendor directory and the built front',
            'end. Does not contain .env, storage/ or any database — those belong',
            'to the installation.',
        ])."\n";
    }

    private function instructions(): string
    {
        return <<<'TXT'
        Installing this update
        ======================

        1. Take a backup from the Backups screen, or run:
               php artisan app:version        (note the schema version first)

        2. Stop the web server.

        3. Unpack `app/` over the existing installation. Nothing in this package
           will overwrite .env, storage/ or the database.

        4. Apply it:
               php artisan app:update

           This takes its own backup before touching anything, applies any
           pending migrations, and prints the new schema version. If it stops
           with an error, nothing after that point ran — restore the backup it
           named and call support before trying again.

        5. Start the web server, and check:
               php artisan app:version

        Rolling back
        ------------
        Restore the backup taken in step 4 and unpack the previous package over
        the installation. Migrations are not reversed automatically: a migration
        that has run has already changed data, and undoing it is a decision, not
        a command.
        TXT;
    }
}
