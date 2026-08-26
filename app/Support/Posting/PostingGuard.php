<?php

namespace App\Support\Posting;

use App\Support\Posting\Exceptions\AlreadyPosted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Claims a document so it can only post once.
 *
 * A double-submitted form, a retried HTTP request, an importer run twice — each
 * arrives as a second attempt to post the same document, and each would
 * otherwise duplicate its stock and its journal. Checking for an existing
 * posting first is not enough: two concurrent requests both pass the check.
 * The unique key on `document_postings` decides it instead, and the loser gets
 * a clear exception.
 *
 * Call inside the posting transaction. A rollback releases the claim, so a
 * posting that failed for any other reason can simply be retried.
 */
final class PostingGuard
{
    public function claim(string $referenceType, string $referenceId, ?string $actorId = null): void
    {
        try {
            DB::table('document_postings')->insert([
                'id' => (string) Str::uuid7(),
                'ref_type' => $referenceType,
                'ref_id' => $referenceId,
                'posted_at' => now(),
                'posted_by' => $actorId,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new AlreadyPosted(
                sprintf('%s %s has already been posted.', $referenceType, $referenceId),
                previous: $exception,
            );
        }
    }
}
