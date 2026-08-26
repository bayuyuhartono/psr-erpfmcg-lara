<?php

namespace Tests\Feature;

use App\Support\Posting\PostingTransaction;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Deliberately without `RefreshDatabase`.
 *
 * That trait holds a transaction open for the whole test, which would make
 * every call here look nested and hide the behaviour being tested — the same
 * reason the load test does without it.
 *
 * These tests raise deadlocks by hand. A synthetic one does not make MySQL
 * discard the transaction the way a real one does, so what is asserted is the
 * decision this class makes — how many times it tries, and whether it tries at
 * all — rather than what the database does afterwards. The real thing is
 * covered by the load test.
 */
final class PostingTransactionTest extends TestCase
{
    public function test_it_retries_a_deadlock_and_gets_there(): void
    {
        $attempts = 0;

        $result = PostingTransaction::run(function () use (&$attempts): string {
            $attempts++;

            if ($attempts < 3) {
                throw new DeadlockException('SQLSTATE[40001]: Deadlock found when trying to get lock');
            }

            return 'posted';
        });

        $this->assertSame('posted', $result);
        $this->assertSame(3, $attempts);
    }

    public function test_it_gives_up_rather_than_retrying_for_ever(): void
    {
        $attempts = 0;

        try {
            PostingTransaction::run(function () use (&$attempts): void {
                $attempts++;

                throw new DeadlockException('SQLSTATE[40001]: Deadlock found when trying to get lock');
            });
            $this->fail('Expected the deadlock to be given up on.');
        } catch (DeadlockException) {
            // Five attempts, then the caller hears about it. A document that
            // cannot get through five is a different problem, and more retries
            // would only hide it.
            $this->assertSame(5, $attempts);
        }
    }

    public function test_a_lock_wait_timeout_counts_as_contention_too(): void
    {
        $attempts = 0;

        PostingTransaction::run(function () use (&$attempts): void {
            $attempts++;

            if ($attempts < 2) {
                throw new RuntimeException('SQLSTATE[HY000]: Lock wait timeout exceeded; try restarting transaction');
            }
        });

        $this->assertSame(2, $attempts);
    }

    public function test_anything_that_is_not_contention_is_not_retried(): void
    {
        $attempts = 0;

        try {
            PostingTransaction::run(function () use (&$attempts): void {
                $attempts++;

                throw new RuntimeException('shelf life too short');
            });
            $this->fail('Expected the exception through.');
        } catch (RuntimeException) {
            // A business rule is not contention. Retrying it would just break
            // the same rule four more times.
            $this->assertSame(1, $attempts);
        }
    }

    public function test_nested_use_leaves_the_retry_to_whoever_owns_the_transaction(): void
    {
        // The hazard this guards: retrying inside somebody else's transaction
        // means rolling back their work and re-running only this fragment of
        // it. So nested, it does not retry — and does not roll anything back.
        $attempts = 0;

        try {
            DB::transaction(function () use (&$attempts): void {
                PostingTransaction::run(function () use (&$attempts): void {
                    $attempts++;

                    throw new DeadlockException('SQLSTATE[40001]: Deadlock found when trying to get lock');
                });
            });
            $this->fail('Expected the deadlock to reach the caller.');
        } catch (DeadlockException) {
            $this->assertSame(1, $attempts, 'Nested use must not retry on its caller\'s behalf');
        }
    }

    public function test_nested_work_that_succeeds_returns_normally(): void
    {
        $value = DB::transaction(fn (): string => PostingTransaction::run(fn (): string => 'inner'));

        $this->assertSame('inner', $value);
        $this->assertSame(0, DB::transactionLevel());
    }
}
