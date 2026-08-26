# Known issues

Things that are wrong, or will become wrong, that were left standing on purpose.
Each entry says what breaks, when it starts mattering, and what fixing it looks
like. Delete an entry when it is fixed — the implementation plan is the record
of what was built, this file is only the record of what was not.

---

## Open

### 1. Goods receipt credits Trade Payables directly — no GR/IR clearing account

`JournalMappingsSeeder` maps `goods_receipt.payable` to 2101, the same account
the supplier invoice will credit at 3.5. Both posting the same liability
double-counts payables for every receipt that has an invoice.

Standard practice is a GR/IR clearing account: the receipt credits clearing, the
invoice debits clearing and credits payables, and the balance in clearing is
goods received but not yet invoiced — which is also what the three-way match
(F-11) reconciles against.

**Bites at:** 3.5, supplier invoice three-way match.
**Fix:** one account in `ChartOfAccountsSeeder`, one row in
`JournalMappingsSeeder`. No code change — that is the point of `journal_mappings`.
**Needs:** the client's accountant to confirm, since it changes the chart.

### 2. Mass `update()` bypasses the document state guards

`HasDocumentState` hangs its guards off model events. `Model::query()->update(['status' => 'posted'])`
does not fire them, so a bulk update can move documents into any status, past
every transition rule and permission check.

**Bites at:** the first module that bulk-closes documents — most likely 5.x
(closing fully shipped orders) or 6.x (marking an invoice batch exchanged).
**Fix:** either forbid it in review, or override `newEloquentBuilder()` on
document models so `update()` refuses when `status` is among the columns.

### 3. Every stock posting queues on one journal number sequence row

`JournalPoster` numbers each entry through `DocumentNumberer`, which locks the
branch-less `JE` sequence row for the rest of the transaction. Every concurrent
goods movement therefore serialises on that single row, on top of the
`product_costs` lock it already takes.

Correct, but it caps posting throughput at one document at a time regardless of
which product or warehouse is involved. Fine at 25 users; it is the first thing
to look at if the load test is slow.

**Bites at:** 9.7, load test with 25 concurrent users.
**Fix:** if measurement shows it matters — number journals per branch, or move
numbering to the end of the transaction so the lock is held for less of it.

### 4. `number_sequences` unique key does not constrain branch-less rows

The key is `(branch_id, document_type, period)`, and MySQL treats NULLs as
distinct in a unique index. Nothing at the database level stops two rows for
`(NULL, 'JE', '2026-09')` existing, and two sequences for the same document type
means duplicate document numbers.

`DocumentNumberer` works around it by locking the previous period's row before
creating a new one, which serialises rollovers. Anything that inserts sequence
rows another way — a seeder, an admin screen — is not protected.

**Bites at:** a Filament screen for number sequences, or any second writer.
**Fix:** a generated column that maps NULL to a sentinel and a unique index over
that, or refuse branch-less sequences and give installation-wide documents a
default branch.

### 5. `StockPosting`'s deadlock retry stops working the moment a module wraps it

`StockPosting::post()` passes `attempts: 3` to `DB::transaction()`. That retry
only happens when the posting is the outermost transaction. Laravel refuses to
retry a *nested* transaction on a deadlock — it rolls back to the savepoint and
rethrows a `DeadlockException` immediately, because MySQL has already discarded
the whole outer transaction (`ManagesTransactions::handleTransactionException`).

Which means the retry is illusory in the case that will dominate: an Action like
`PostGoodsReceipt` saving the document and posting stock inside one transaction,
exactly as the architecture prescribes. Two concurrent receipts for the same
product deadlock and one surfaces as an error to the user instead of retrying.

Not wrong today — nothing wraps a posting yet — but it will be quietly wrong
from the first module.

**Bites at:** 3.2, the first Action that wraps a posting in its own transaction.
**Fix:** retry at the Action boundary, where the transaction actually begins,
and drop the misleading attempt count from `StockPosting`. Worth a concurrency
test that wraps a posting the way a module will.

### 6. Nothing physically stops a module skipping the posting claim

`StockPosting::postDocument()` claims the document before posting; the
single-movement `post()` underneath it does not, because `StockReversal` needs
an unclaimed path. Both are public — PHP has no package-private — so a module
that calls `post()` in a loop over its lines gets no idempotency and no error.

It is documented in the method's docblock and in architecture.md, which means
it depends on someone reading them.

**Bites at:** any module written by someone who reached for the obvious-looking
method. Silent: the symptom is a document that posts twice.
**Fix:** review checklist for now — `post()` outside `StockPosting` and
`StockReversal` is a bug. If it happens twice, move reversal onto its own entry
point and make `post()` private.

### 7. Kernel-posted journal entries carry no description

`StockPosting` posts entries with `ref_type` and `ref_id` set but `description`
null, so a general ledger listing shows blank narration on every automatic line.
Drill-through (F-61) works, reading the GL without it does not.

**Bites at:** 7.4, general ledger.
**Fix:** pass a description down from the module Action that owns the document —
it is the only layer that knows the document number and partner name.
