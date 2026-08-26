# Architecture — FMCG Distributor ERP

Decided 2026-08-25. Laravel 13, PHP 8.4+, MySQL 9.7, Filament v5.

> **Status:** the M0 foundation is implemented: the domain tree, Eloquent
> models, decimal handling, audit observer, numbering service, authorization,
> seed data, and Filament panel now exist. `StockPosting` and `JournalPoster`
> are in place — a movement writes ledger, balance, cost, and journal in one
> transaction, `StockReversal` undoes one by counter-entry, and `DocumentStates`
> guards every status change, a document can only post once, and a closed
> accounting period refuses backdated postings, and `stock:rebuild-balances`
> proves the cache against the ledger. M1 to M4 are complete; M5 sales and
> delivery is next.

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
    Posting/       StockPosting, JournalPoster, StockReversal, guards
    Documents/     DocumentStates base, transition guards
    Import/        all-or-nothing spreadsheet import
    Uom/           base-unit conversion, line snapshots
    ShelfLife/     expiry calculation, receipt and shipping thresholds
    Pricing/       price list resolution, compounding discounts
    Money/         decimal casts, rounding rules
    Audit/         model observer writing audit_logs

  Filament/        Resources and Pages   ← thin
    Concerns/      ReportsDomainRules
    Forms/         DecimalInput
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
PostAdjustment ───────┼──▶ StockPosting::postDocument()
PostStockTransfer ────┤        │
SettleConsignment ────┤        ├─ 0. claim the document  (unique key)
PostSalesReturn ──────┘        │
                               └─ per movement:
                                  ├─ 1. append stock_ledger row
                                  ├─ 2. upsert stock_balances
                                  ├─ 3. recompute product_costs  (row-locked)
                                  └─ 4. JournalPoster::post()
                            all inside one DB transaction
```

Modules call `postDocument()`, never the single-movement `post()` underneath
it. The claim is what makes a double-submitted form, a retried request, or an
importer run twice post once: `document_postings` has a unique key on the
document reference, so a race is decided by the database rather than by a
check two concurrent requests would both pass. A failed posting rolls its claim
back with everything else, so a corrected resubmission of the same document
number still works.

Five rules, each traceable to a specific failure:

1. **One transaction.** Ledger, balances, cost, and journal commit together or
   not at all. A partial write is unrecoverable inventory corruption.
2. **`SELECT … FOR UPDATE` on `product_costs`.** Moving average computed without
   a row lock is wrong the first time two receipts post concurrently, and it
   surfaces months later during an audit. This has a dedicated concurrency test.
3. **Append only.** Never UPDATE, never DELETE a ledger row. Reversals are new
   negative rows with a counter-journal. This is why `stock_ledger` has no
   `updated_at` or `deleted_at` — the columns would imply an edit path exists.
   `StockReversal` mirrors a document's rows back through `StockPosting`, so the
   counter-journal falls out of the sign flip instead of being assembled
   separately, and each mirror row names the row it cancels in
   `reverses_ledger_id` — uniquely indexed, so a document cannot be reversed
   twice even under concurrent cancellation. A reversal backs out the value the
   original posted, never today's average: re-pricing on the way out would let a
   cancelled receipt silently revalue stock that was already on hand.
4. **Balances are a cache.** `stock_balances` must be rebuildable from the ledger
   at any time, and `stock:rebuild-balances` does it. If the rebuild disagrees
   with the cache, the ledger is right. `--check` is read-only and exits
   non-zero, so it doubles as a scheduled health check: anything it reports
   means something wrote stock without going through the kernel.
5. **A document posts once.** Claimed by unique key, not by asking first. The
   symptom of getting this wrong is stock that is silently double-counted, which
   is the failure the whole kernel exists to prevent.
6. **A closed month stays closed.** `PeriodGuard` is checked by `StockPosting`
   and by `JournalPoster` separately, because a manual journal touches no stock
   and a transfer posts no journal — one check would leave a way in. A month
   with no `accounting_periods` row is open: locking is a decision someone
   makes, and not having made it is not a lock.

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

### Filament stays thin, and two things make that work

A resource is a form and a table. Where a rule exists, the form calls the
Action — the product units manager calls `AddProductUom` rather than writing
the row — because a form that saves directly becomes a second, quietly
different set of rules the importer and API do not share.

Domain rules throw, wherever they are broken. `ReportsDomainRules` catches
`DomainException` on create and update pages and shows the message, so a locked
base unit or a partner losing a role in use reads as a sentence rather than a
stack trace. It catches nothing wider: a query error is a bug, and burying it
in a toast would hide it.

`DecimalInput` exists because Filament's `->numeric()` attaches a state cast
that runs `floatval()` on the value. On a `BigDecimal` that throws, and if it
did not it would put money back into float arithmetic — the one thing the
decimal casts exist to prevent. Use it for anything declared with the
`money()`, `qty()`, `cost()` or `percent()` macros; plain `->numeric()` is for
genuine integers.

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

Each document declares its moves in a `DocumentStates` subclass under its
context's `States/`, and the `HasDocumentState` trait enforces them:
`transitionTo()` is the only way a status changes, assigning to `status` and
saving throws, and a document in a frozen status refuses further edits and
deletes. Frozen is not stuck — a posted document can still be reversed or
cancelled; it is its own fields that stop moving, because the correction
belongs in a counter-entry.

The guards hang off model events, so a mass `update()` on the query builder
slips past them. Documents are transitioned one at a time, deliberately.

### Units

The base unit is the only unit stock is stored in; `stock_ledger` knows nothing
but `qty_base`. `UomConverter` converts on the way in and hands back a
`UomSnapshot` carrying all four values a transaction line stores — unit,
quantity, factor, base quantity — because the factor must be *copied* onto the
line. Read it back from the master later and last year's invoices change
quantity the day someone corrects a carton size.

Changing a product's `base_uom` after it has moved is blocked on the model, not
in an action: it would reinterpret every historical quantity rather than convert
it, and the importer and API have to obey the rule as much as the UI does.

### Bulk import

Onboarding imports are all-or-nothing (F-02). A client who ends up with 1,800
of 3,000 products and no reliable account of which is worse off than one whose
import failed outright.

`preview()` earns that by running the real import inside a transaction and
throwing it away. Shape checks alone cannot tell you a row will be accepted —
the domain rules decide, and the only way to ask them is to try. So the problem
list a check produces is exactly the one a commit would produce, including
shelf-life rejections and locked base units, rather than a weaker guess at it.

Opening stock posts as a single document through `postDocument()`, not row by
row: one claim, one transaction, and the moving average and journals that a
bulk insert into `stock_ledger` would have skipped.

### Pricing

`PriceResolver` answers one question — what does this line cost — and returns a
`PriceQuote` that maps onto the line's columns, the same shape as
`UomSnapshot`. Prices and discount percentages are copied onto the line, never
read back from the master.

Discounts **compound sequentially**: 5% then 3% then 2% leaves 90.307% of the
price, not 90%. Summing them is the most common way a distributor's invoices
stop matching the principal's promotion terms, and it is invisible until
someone reconciles a quarter. The percentages are kept apart rather than
collapsed into one, because a customer asking why an invoice says what it says
needs to see the tiers, not the product of them. A line records at most three;
a fourth matching rule is refused rather than silently dropped.

### Shelf life

`ShelfLifePolicy` holds both rules from F-03. They differ in shape on purpose:
`assertAcceptableOnReceipt()` throws, because accepting short-dated goods is a
decision someone has to be stopped from making; `isShippable()` returns a
boolean, because it filters the picking pool and a batch below the threshold is
simply never offered (F-20).

Expired stock is never shipped and never received, whether or not a threshold
is configured. A percentage of remaining shelf life is a judgement about how
much life is enough; none at all is not a judgement.
Product settings that cannot work — expiry tracking without batch tracking, a
receipt percentage of a shelf life that is not set — are refused on the model,
because a misconfigured product passes every shelf-life check silently.

### Money and quantities

Decimal all the way down. Columns are `decimal(18,2)` for money, `decimal(18,4)`
for quantities and unit costs, created through the `money()`, `qty()`, `cost()`,
and `percent()` Blueprint macros in `SchemaServiceProvider`. Casts keep them out
of PHP float arithmetic. Rounding happens once, at presentation.

### Approvals that record a decision

Where a rule can be overridden — a supplier invoice out of tolerance, a
discount above a threshold, a credit hold — the override is an approval by a
named person with a permission, not an edit that makes the numbers agree. The
document keeps saying what it always said; what changes is that someone
accountable decided to accept it.

`role_permissions.threshold` carries the ceiling where there is one, so
"may approve" and "may approve *this much*" are different answers with
different exceptions behind them.

### Sensitive fields

What a product cost us — and therefore the margin on it — is the one thing an
owner asks to be kept from the sales floor (F-70). It is a `view-cost` Gate
ability over the `product.view_cost` permission, defined once so the screens,
the exports and the Phase 2 API ask the same question rather than each
deciding. Margin hides with cost, because a price against a known cost is the
cost.

The gate is the decision; it is not by itself containment. Anything that
returns a record wholesale — an export, a JSON response — has to ask it too,
and that is a review point for every new endpoint rather than something the
model can enforce.

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
