<?php

namespace App\Support\Backup;

use App\Support\Backup\Exceptions\BackupFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Takes and restores a copy of the whole database.
 *
 * Manual on purpose. Scheduling this needs to know the client's server, where
 * offsite is, and who administers it — none of which is answered yet (PRD §10
 * Q14). What can be built without those answers is the thing an administrator
 * presses before an upgrade, and the restore that proves the file is worth
 * having.
 *
 * The credentials go through the environment rather than the command line: an
 * argument is visible to every process on the machine.
 */
final class DatabaseBackup
{
    public function directory(): string
    {
        $path = config('backup.path');

        if (! is_dir($path)) {
            mkdir($path, 0700, true);
        }

        return $path;
    }

    /** @return array{name: string, path: string, bytes: int} */
    public function create(?string $label = null): array
    {
        $connection = config('database.connections.'.config('database.default'));
        $name = sprintf(
            '%s-%s%s.sql',
            $connection['database'],
            CarbonImmutable::now()->format('Ymd-His'),
            $label === null ? '' : '-'.preg_replace('/[^a-z0-9\-]/i', '-', $label),
        );
        $path = $this->directory().DIRECTORY_SEPARATOR.$name;

        $process = new Process([
            config('backup.mysqldump'),
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            '--single-transaction',   // consistent without locking the warehouse out
            '--routines',
            '--events',
            // Without this the dump carries the server's GTID state, and
            // restoring it back onto the same server is refused — which makes
            // the file useless for the one thing it is for. Single-server
            // on-premise installations have no replication to preserve.
            '--set-gtid-purged=OFF',
            '--result-file='.$path,
            $connection['database'],
        ], env: ['MYSQL_PWD' => (string) $connection['password']], timeout: config('backup.timeout_seconds'));

        $process->run();

        if (! $process->isSuccessful() || ! is_file($path)) {
            @unlink($path);

            throw new BackupFailed('Could not write a backup: '.trim($process->getErrorOutput() ?: 'mysqldump produced no output.'));
        }

        chmod($path, 0600);

        return ['name' => $name, 'path' => $path, 'bytes' => (int) filesize($path)];
    }

    /**
     * Loads a dump into a database.
     *
     * Defaults to a different schema than the live one, because the useful
     * thing to do with a backup before you need it is prove it restores —
     * pointing this at the live database by accident is the other kind of
     * useful.
     */
    public function restore(string $path, ?string $intoDatabase = null): void
    {
        if (! is_file($path)) {
            throw new BackupFailed(sprintf('No backup file at %s.', $path));
        }

        $connection = config('database.connections.'.config('database.default'));
        $target = $intoDatabase ?? $connection['database'].'_restore_check';

        DB::statement(sprintf('CREATE DATABASE IF NOT EXISTS `%s`', $target));

        $process = Process::fromShellCommandline(
            sprintf(
                '%s --host=%s --port=%s --user=%s %s < %s',
                escapeshellcmd(config('backup.mysql')),
                escapeshellarg($connection['host']),
                escapeshellarg((string) $connection['port']),
                escapeshellarg($connection['username']),
                escapeshellarg($target),
                escapeshellarg($path),
            ),
            env: ['MYSQL_PWD' => (string) $connection['password']],
            timeout: config('backup.timeout_seconds'),
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new BackupFailed('Could not restore: '.trim($process->getErrorOutput()));
        }
    }

    /** @return list<array{name: string, path: string, bytes: int, taken_at: string}> */
    public function all(): array
    {
        $files = glob($this->directory().DIRECTORY_SEPARATOR.'*.sql') ?: [];

        $backups = array_map(fn (string $path): array => [
            'name' => basename($path),
            'path' => $path,
            'bytes' => (int) filesize($path),
            'taken_at' => CarbonImmutable::createFromTimestamp(filemtime($path))->format('Y-m-d H:i'),
        ], $files);

        usort($backups, fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $backups;
    }

    public function delete(string $name): void
    {
        // Only ever a file this class wrote, in the directory it owns.
        $path = $this->directory().DIRECTORY_SEPARATOR.basename($name);

        if (is_file($path)) {
            unlink($path);
        }
    }
}
