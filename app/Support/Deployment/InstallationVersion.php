<?php

namespace App\Support\Deployment;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * What is installed here (9.3).
 *
 * Every client runs their own copy, so "which version is this one on" is a
 * question support has to be able to answer over the phone about a machine
 * they cannot see. The answer is the schema: the last migration applied is the
 * only thing that reliably distinguishes two installations.
 *
 * The schema doc has a `schema_migrations` table for this. It was never built,
 * because Laravel's `migrations` table already is one — a second copy of the
 * same fact is a second thing to get out of step.
 */
final class InstallationVersion
{
    /**
     * @return array{schema: string, applied: int, pending: int, batches: int, commit: ?string, up_to_date: bool}
     */
    public function describe(): array
    {
        $applied = DB::table('migrations')->count();
        $pending = $this->pending();

        return [
            // The migration filename, which sorts chronologically and is what
            // a release note can refer to.
            'schema' => (string) (DB::table('migrations')->orderByDesc('id')->value('migration') ?? 'not installed'),
            'applied' => $applied,
            'pending' => $pending,
            'batches' => (int) (DB::table('migrations')->max('batch') ?? 0),
            'commit' => $this->commit(),
            'up_to_date' => $pending === 0,
        ];
    }

    /** Migrations shipped in this code that this database has not run. */
    public function pending(): int
    {
        $applied = DB::table('migrations')->pluck('migration')->all();

        $shipped = array_map(
            fn (string $path): string => basename($path, '.php'),
            glob(database_path('migrations').'/*.php') ?: [],
        );

        return count(array_diff($shipped, $applied));
    }

    /**
     * Useful when a client's copy came from a build rather than a tag. Absent
     * on an installation deployed from a package, which is expected.
     */
    private function commit(): ?string
    {
        $process = new Process(['git', 'rev-parse', '--short', 'HEAD'], base_path(), timeout: 5);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }
}
