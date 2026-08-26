<?php

namespace App\Support\Posting;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The transaction a posting Action opens, with the deadlock retry that only
 * works here.
 *
 * MySQL discards the whole transaction on a deadlock, so Laravel refuses to
 * retry a *nested* one — it rethrows immediately. Which meant `attempts` on
 * `StockPosting`'s own transaction was never doing anything once an Action
 * wrapped it, and every Action does. Under twenty-five concurrent users
 * receiving the same product that lost 58% of the work.
 *
 * The retry therefore belongs where the outermost transaction actually
 * begins: here. It is written out rather than handed to `DB::transaction`'s
 * own attempt count for two reasons — a deadlock raised inside a nested
 * transaction arrives as a rethrown `DeadlockException` rather than the
 * driver's exception, and two transactions retrying in step simply deadlock
 * again, so the wait is jittered.
 *
 * The work must tolerate running twice. Everything it touched was rolled back,
 * but Eloquent models mutated in the failed attempt are stale — so an Action
 * refreshes its document at the top of the closure rather than trusting what
 * is in memory.
 */
final class PostingTransaction
{
    /**
     * Enough for a busy warehouse. Deadlocks at this load resolve in
     * milliseconds; a document that cannot get through five attempts has a
     * different problem, and burying it in more retries would hide it.
     */
    private const ATTEMPTS = 5;

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function run(callable $work): mixed
    {
        // Already inside somebody's transaction, so this is not the outermost
        // boundary and the retry is not ours to make. Rolling back here would
        // discard the caller's work and retry only this fragment of it —
        // which is worse than the deadlock. The caller owns the retry.
        if (DB::transactionLevel() > 0) {
            return DB::transaction($work);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($work);
            } catch (Throwable $exception) {
                if ($attempt >= self::ATTEMPTS || ! self::isDeadlock($exception)) {
                    throw $exception;
                }

                self::abandonTransaction();

                // Jittered, and widening: two documents backing off by the
                // same amount collide again on the next attempt.
                usleep(random_int(5_000, 20_000) * $attempt);
            }
        }
    }

    /**
     * MySQL has already rolled the transaction back; Laravel's counter has
     * not always caught up when the failure came from a nested level.
     */
    private static function abandonTransaction(): void
    {
        while (DB::transactionLevel() > 0) {
            try {
                DB::rollBack();
            } catch (Throwable) {
                break;
            }
        }
    }

    private static function isDeadlock(Throwable $exception): bool
    {
        if ($exception instanceof DeadlockException) {
            return true;
        }

        if ($exception instanceof QueryException && ($exception->errorInfo[0] ?? null) === '40001') {
            return true;
        }

        return str_contains($exception->getMessage(), 'Deadlock found when trying to get lock')
            || str_contains($exception->getMessage(), 'Lock wait timeout exceeded');
    }
}
