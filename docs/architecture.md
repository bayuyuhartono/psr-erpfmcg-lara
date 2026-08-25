# Architecture — FMCG Distributor ERP

Decided 2026-08-25. Laravel 13, PHP 8.4+, MySQL 9.7, Filament v5.

> **Status:** the M0 foundation is implemented: the domain tree, Eloquent
> models, decimal handling, audit observer, numbering service, authorization,
> seed data, and Filament panel now exist. The posting kernel remains M1 work.

---

## 1. The forces that decided this

Three constraints from the [PRD](prd-fmcg-distributor-erp-en.md) and
[schema](fmcg-distributor-erp-schema-en.md) determine the shape. Nothing else
does.

**Exactly one code path writes stock.** Schema §11 states it outright: every
document that moves goods writes the ledger, updates balances, recomputes
average cost, and posts the journal — through the same function. Per-module
stock logic guarantees one module eventually forgets a step, and the symptom is
silently wrong inventory discovered at a physical count.

**Filament today, a mobile API in Phase 2.** The same "post a goods receipt"
operation must be callable from a Filament action, an HTTP controller, an Excel
importer, a console command, and a test. Business logic therefore cannot live
inside Filament classes, or Phase 2 rewrites it.

**Two to three developers, eight to nine months, many installations.** Every
layer of indirection is a layer the next maintainer must understand before they
can fix a stock bug at 11pm on someone else's server. The real risks here are
stock correctness and month-end close, not modelling purity.

---

## 2. The pattern

**A modular monolith organised by bounded context, with single-purpose Action
classes as the unit of business logic, over one central posting kernel.**

```
app/
  Domain/
    Masters/       products, UOMs, partners, price lists, imports
    Purchasing/    PO, goods receipt, supplier invoice, returns, claims
    Inventory/     ledger, balances, FEFO, counts, adjustments, transfers
    Sales/         orders, credit hold, delivery, invoicing, returns
    Consignment/   settlements, both directions
    Finance/       invoice exchange, payments, allocation, cheques
    Accounting/    chart of accounts, journals, periods, reports

    <Context>/
      Models/      Eloquent models for this context's tables
      Actions/     one class, one operation, invokable
      States/      document status transitions
      Services/    context-internal helpers, if an Action is not enough
      Events/      what happened, past tense

  Support/
    Posting/       StockPosting, JournalPoster, DocumentNumberer
    Money/         decimal casts, rounding rules
    Audit/         model observer writing audit_logs

  Filament/        Resources and Pages   ← thin
  Http/Api/        Controllers           ← thin
```

### Dependency rules

- `Filament/` and `Http/Api/` may depend on `Domain/` and `Support/`. Never the
  reverse. If a domain class imports anything from `Filament`, that is a bug.
- A context may depend on `Support/` freely.
- Cross-context calls go through the other context's **Actions**, never its
  models or queries directly. `Sales` asks `Inventory` to reserve stock; it does
  not touch `stock_balances`.
- `Masters` is depended upon by everyone and depends on no one.

---

## 3. The posting kernel

This is the part that matters. Everything else is arrangement.

`app/Support/Posting/StockPosting.php` is the **only** class in the codebase
that writes `stock_ledger`. Every goods movement funnels through it:

```
PostGoodsReceipt ─────┐
ShipDelivery ─────────┤
PostAdjustment ───────┼──▶ StockPosting::post()
PostStockTransfer ────┤        │
SettleConsignment ────┤        ├─ 1. append stock_ledger row
PostSalesReturn ──────┘        ├─ 2. upsert stock_balances
                               ├─ 3. recompute product_costs  (row-locked)
                               └─ 4. JournalPoster::post()
                            all inside one DB transaction
```

Four rules, each traceable to a specific failure:

1. **One transaction.** Ledger, balances, cost, and journal commit together or
   not at all. A partial write is unrecoverable inventory corruption.
2. **`SELECT … FOR UPDATE` on `product_costs`.** Moving average computed without
   a row lock is wrong the first time two receipts post concurrently, and it
   surfaces months later during an audit. This has a dedicated concurrency test.
3. **Append only.** Never UPDATE, never DELETE a ledger row. Reversals are new
   negative rows with a counter-journal. This is why `stock_ledger` has no
   `updated_at` or `deleted_at` — the columns would imply an edit path exists.
4. **Balances are a cache.** `stock_balances` must be rebuildable from the ledger
   at any time, and there is a command that does it. If the rebuild disagrees
   with the cache, the ledger is right.

`JournalPoster` reads `journal_mappings` for the document type. **No account
code is ever hardcoded in posting logic** — clients have different charts of
accounts, and a hardcoded code is a per-client fork.

---

## 4. Conventions

### Actions

One class, one business operation, invokable, framework-agnostic:

```php
final class PostGoodsReceipt
{
    public function __construct(
        private StockPosting $posting,
        private DocumentNumberer $numberer,
    ) {}

    public function __invoke(GoodsReceipt $receipt, User $actor): GoodsReceipt
    {
        // validate shelf life, assign number, post stock, transition state
    }
}
```

Actions are where validation that is *business* rules lives — shelf-life
rejection, credit limit checks, three-way match tolerance. Form-shape validation
stays in form requests and Filament schemas.

### Eloquent, directly

**No repository layer.** Repositories over Eloquent duplicate the query builder
and buy a database swap that will never happen — this ships on MySQL, on the
client's own server. Models are the persistence layer. Scopes and query objects
handle complex reads.

**`OwnedStock` global scope.** Any query summing inventory value must filter
`owner_id IS NULL`. Consigned-in goods sit in our warehouse but belong to the
principal; missing this overstates the balance sheet by their full value. Schema
§11 flags it, so it is enforced by a scope rather than by remembering.

### Events, synchronously

Domain events fire inside the transaction and their listeners run
**synchronously**. On-premise means nobody guarantees a queue worker stays
alive on a distributor's office PC, and a silently dead worker that stops
posting journals is far worse than a slightly slower save.

Queues are reserved for Excel imports and heavy report generation, where a
stalled job is visible and harmless.

### Document state machines

Status transitions are explicit and guarded — `draft → posted → cancelled`, with
each transition naming who may perform it. This is where posting bugs live: a
document posted twice, or edited after posting, or cancelled without a reversal.
A status string updated by hand anywhere in the codebase is a bug.

### Money and quantities

Decimal all the way down. Columns are `decimal(18,2)` for money, `decimal(18,4)`
for quantities and unit costs, created through the `money()`, `qty()`, `cost()`,
and `percent()` Blueprint macros in `SchemaServiceProvider`. Casts keep them out
of PHP float arithmetic. Rounding happens once, at presentation.

### Per-client variation is data, not code

`journal_mappings`, print templates, `role_permissions.threshold`,
`company_settings` — these exist so a client's differences never require a
branch. The PRD names scope creep from the first client as a product-killing
risk: the moment a request needs a code change, it is paid customisation, not a
product feature.

---

## 5. Testing

Effort is not spread evenly. It concentrates where being wrong is expensive and
silent:

| Area | Depth |
|---|---|
| `StockPosting`, moving average, concurrency | Heaviest. Including parallel-write tests |
| Journal correctness, period locks | Heavy. Debits equal credits, closed periods reject |
| Actions: credit hold, FEFO, shelf life, allocation | Feature tests per requirement code |
| Filament resources | Smoke tests only — they are thin by design |
| Reports | Golden-file tests against a seeded dataset |

Requirement codes (F-xx) from PRD section 7 are testable as written; name tests
after them so coverage maps to the acceptance criteria.

---

## 6. What was rejected, and why

**Repositories over Eloquent.** Duplicate the query builder, add a layer to
every read, and deliver a database-swap capability that single-tenant on-premise
MySQL deployment will never use.

**Full DDD** — aggregate roots separate from Eloquent, domain/persistence
mapping, CQRS. Fights both Eloquent and Filament, and costs a 2–3 developer team
more than it returns on an 8–9 month runway. The risks here are stock
correctness and closing the month, not modelling purity.

**Event sourcing.** Genuinely tempting, because `stock_ledger` is already an
append-only fact table. But that table *already* provides replay and audit for
the one thing that needs them. Adopting ES across the system would multiply the
surface area for no added safety, and narrows who can maintain it.

**Queued domain listeners.** Better throughput, but requires a supervised queue
worker on every client's on-premise server. A dead worker that silently stops
posting journals is exactly the class of failure this product exists to
eliminate.

**Plain `app/Models` + `app/Services`.** Faster for the first two modules, then
the boundaries blur — and nothing structurally prevents a module writing stock
itself, which is the one rule that must not be breakable by accident.
