# Known issues

Things that are wrong, or will become wrong, that were left standing on purpose.
Each entry says what breaks, when it starts mattering, and what fixing it looks
like. Delete an entry when it is fixed — the implementation plan is the record
of what was built, this file is only the record of what was not.

Two accounting choices here were made without an accountant, because this is
still a demo: a **GR/IR clearing account** (2103) that the goods receipt credits
and the supplier invoice will clear, and **Opening Balance Equity** (3102) for
imported opening stock. Both are standard, and both are rows in
`ChartOfAccountsSeeder` and `JournalMappingsSeeder` — a real client's accountant
can overrule either without a code change. Confirm before the first live
install.

---

## Open

### 1. Mass `update()` bypasses the document state guards

`HasDocumentState` hangs its guards off model events. `Model::query()->update(['status' => 'posted'])`
does not fire them, so a bulk update can move documents into any status, past
every transition rule and permission check.

**Bites at:** the first module that bulk-closes documents — most likely 5.x
(closing fully shipped orders) or 6.x (marking an invoice batch exchanged).
**Fix:** either forbid it in review, or override `newEloquentBuilder()` on
document models so `update()` refuses when `status` is among the columns.

### 2. Every stock posting queues on one journal number sequence row

**Measured at 9.7 and not a problem.** Twenty-five concurrent users sustain
150–200 documents per second through that single lock, with a median latency
around 100–150 ms. A distributor would need roughly nine thousand documents a
minute to saturate it.

Left here because the reasoning still holds — it is a global lock, and it is
the first thing to look at if throughput ever matters — but it needs no work.

### 3. `number_sequences` unique key does not constrain branch-less rows

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

### 4. Nothing physically stops a module skipping the posting claim

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

### 5. Where consigned-in stock physically sits is still undecided

Schema §7 says inbound consignment works through `owner_id`, with the goods in
"a real warehouse". Section 4 also defines a `consignment_in` warehouse type
carrying the principal's `partner_id`. Both are supported and they imply
different invariants:

- goods in a normal warehouse with `owner_id` set, or
- goods in a `consignment_in` warehouse dedicated to one principal.

Until it is settled, the kernel cannot check that `owner_id` and the warehouse
agree — which is the check that would stop a principal's goods being posted as
ours, the balance-sheet overstatement rule 4 exists to prevent. Right now only
`owner_id` is enforced, and the warehouse type is not consulted.

**Bites at:** P2.6, inbound consignment. Also affects what the expiry and
valuation reports have to filter on.
**Fix:** pick one, write it into the schema doc, then add the matching
assertion to `StockPosting::validate()`. A settlement moving goods into our
ownership while they sit still is the case that decides it.

### 6. The model generator misses pivot relations

It derives relations from foreign keys, so a pivot table gives it two
`hasMany`s and no `belongsToMany`. `Delivery::salesOrders()` was written by
hand for that reason (F-41); `invoice_exchanges` ⇄ `sales_invoices` will need
the same when M6 arrives.

**Bites at:** 6.1, invoice exchange batches.
**Fix:** write the relation by hand as each module needs it. The generator now
skips existing files, so it will not remove them.

### 7. The test suite needs 512 MB

`phpunit.xml` raises `memory_limit` because two tests build the volumes the PRD
names as acceptance criteria — 3,000 opening stock rows (F-02), and 5,000 SKUs
with 2,000 outlets (F-31). Each peaks around 80 MB, and PHP's CLI default of
128 MB will not hold one of them alongside the rest of the suite in one
process.

Not a leak: the one real retention bug behind this was `postDocument()` holding
a result object per line, fixed in 1.9. But it does mean the suite will fail
confusingly on a machine with a lower limit, and both tests are in the `slow`
group if they need to be excluded.

**Bites at:** CI, or anyone running the suite with a tighter `php.ini`.
**Fix:** if it becomes awkward, run the `slow` group in its own process.

### 8. `sortBy` with an array of closures silently does not sort

`$collection->sortBy([fn ($x) => ..., fn ($x) => ...])` returns the original
order. Only the property/direction form — `sortBy([['a', 'asc'], ['b', 'asc']])`
— sorts on multiple keys. It fails silently, which is how it survived a passing
test in the picking list until a third row made the order matter.

Both callers now order in SQL instead, which is also where ordering belongs.

**Bites at:** anywhere a multi-key sort looks right and is not.
**Fix:** order in the query. If a collection sort is genuinely needed, use the
pairs form and test it with enough rows to distinguish sorted from unsorted.

### 9. Backups are manual, and offsite copying is a human step

The screen takes a dump, downloads it, and proves it restores. It does not
schedule anything and does not copy anything off the machine, because
scheduling needs to know the server, the OS and where offsite is — PRD §10 Q14,
unanswered.

A backup that only exists on the server it backs up survives a bad upgrade and
nothing else.

**Bites at:** the first hardware failure at a client.
**Fix:** once the server is known — cron or Task Scheduler, and rsync, S3 or a
swapped disk. `DatabaseBackup::create()` is already the whole of the local
half.

### 10. Kernel-posted journal entries carry no description

`StockPosting` posts entries with `ref_type` and `ref_id` set but `description`
null, so a general ledger listing shows blank narration on every automatic line.
Drill-through (F-61) works, reading the GL without it does not.

**Bites at:** 7.4, general ledger.
**Fix:** pass a description down from the module Action that owns the document —
it is the only layer that knows the document number and partner name.
