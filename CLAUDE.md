# CLAUDE.md

ERP for mid-sized Indonesian FMCG distributors. Single-tenant, deployed on each
client's own server — one database per client, no `company_id` anywhere.

## Environment — read this first

`php` on PATH is Homebrew **8.2.32**. This project requires **PHP >= 8.4.1**, so
every `php artisan` call fails with a Composer `platform_check.php` fatal that
looks like a broken install but is not.

Use Herd's binary for everything:

```bash
alias erp-php=/Users/bayuyuhartono/.config/herd-lite/bin/php   # 8.5.0

erp-php artisan migrate
erp-php artisan migrate:fresh --force
erp-php vendor/bin/pint
erp-php artisan test
erp-php /opt/homebrew/bin/composer require <pkg>   # composer too
```

Database is MySQL 9.7 at `127.0.0.1:3306`, schema `psr_erpfmcg`, user `root`, no
password. `mysql -h127.0.0.1 -uroot psr_erpfmcg` connects.

## Documents, and which one wins

| File | Role |
|---|---|
| [docs/prd-fmcg-distributor-erp-en.md](docs/prd-fmcg-distributor-erp-en.md) | Requirements. F-xx codes in section 7 are the acceptance criteria |
| [docs/fmcg-distributor-erp-schema-en.md](docs/fmcg-distributor-erp-schema-en.md) | Original schema — **PostgreSQL, and now partly superseded** |
| [docs/architecture.md](docs/architecture.md) | How the code is organised, and why |
| [docs/implementation-plan.md](docs/implementation-plan.md) | Task list with per-item status. Keep it current |
| [docs/known-issues.md](docs/known-issues.md) | Deliberately unfixed problems, and when each starts to bite |

**The migrations are authoritative where they disagree with the schema doc.**
The doc still says PostgreSQL and still offers FIFO. Deviations are commented
inline in the migration that makes them. Do not "fix" a migration to match the
doc without reading that comment first.

## Decisions already locked

- **MySQL**, not the PostgreSQL of the schema doc
- **UUIDv7 `char(36)`** primary keys — the Phase 2 offline sales app generates
  IDs client-side, so bigint would force temp-ID reconciliation on sync
- **Moving average costing only.** `costing_method` was deliberately omitted
  rather than shipped accepting one value
- **Filament v5** for the whole UI
- **Modular monolith + Action classes**, one posting kernel, no repositories

## Rules that must not be broken

These come from schema §11 and each has a specific, silent failure behind it.

1. **Exactly one code path writes stock.** Every goods movement goes through
   `StockPosting` — ledger row, balance upsert, cost recompute, journal, in one
   transaction. Never write `stock_ledger` from a module.
2. **Lock `product_costs` with `SELECT … FOR UPDATE`** in that same transaction.
   Moving average without the row lock is wrong the first time two receipts post
   concurrently, and surfaces months later in an audit.
3. **`stock_ledger` is append-only.** No UPDATE, no DELETE. Reversals are new
   negative rows with a counter-journal. That is why the table has no
   `updated_at` or `deleted_at`.
4. **Filter `owner_id IS NULL`** on any query summing inventory value.
   Consigned-in goods sit in our warehouse but belong to the principal; missing
   this overstates the balance sheet by their full value.
5. **Never float.** Money is `decimal(18,2)`, quantities and unit costs
   `decimal(18,4)`. Use the Blueprint macros below.
6. **Snapshot, never reference.** Prices, unit conversions, and discount rates
   are copied onto transaction lines at creation. Reading them from the master
   later means last year's invoices silently change value.
7. **No account code hardcoded** in posting logic — read `journal_mappings`.
   Every client has a different chart of accounts.
8. **Batch is chosen at picking, not ordering.** That is why `batch_id` lives on
   `delivery_lines` and not `sales_order_lines`.
9. **Shelf-life rules are hard validations, server-side.**
   `min_life_on_receipt_pct` blocks a goods receipt; `min_life_on_ship_days`
   removes batches from the picking pool. Not warnings.

## Conventions

Blueprint macros from `app/Providers/SchemaServiceProvider.php` — use them in
every migration instead of raw `decimal()`:

```php
$table->auditable();          // created_at/by, updated_at/by, deleted_at
$table->money('total');       // decimal(18,2)
$table->qty('qty_base');      // decimal(18,4)
$table->cost('unit_cost');    // decimal(18,4)
$table->percent('vat');       // decimal(5,2)
```

Business logic lives in Action classes under `app/Domain/<Context>/Actions/`,
callable from Filament, the API, importers, and tests alike. Filament resources
and API controllers stay thin — validate, then call the Action. A domain class
importing anything from `App\Filament` is a bug.

Per-client variation belongs in data — `journal_mappings`, print templates,
`role_permissions.threshold` — never in forked code.

## Current state

Schema is complete and verified: 79 tables, 150 foreign keys, 4 CHECK
constraints, `migrate` → `migrate:reset` → `migrate` clean in both directions.

Built: the `app/Domain` tree and Eloquent models, and the posting kernel —
`StockPosting` (ledger, balance, moving average, journal in one transaction),
`JournalPoster` (accounts from `journal_mappings`, never hardcoded),
`StockReversal` (counter-entries, never an edit), `DocumentNumberer`, and the
`DocumentStates` machinery that makes a hand-assigned status throw.

Modules post through `StockPosting::postDocument()`, not the single-movement
`post()` underneath it — the document-level claim is what stops a double
submission double-counting stock.

Not built yet: Filament screens and every feature module. M1 is complete. See the implementation plan for what is next
and what is blocked on client answers.

Tests run against MySQL (`psr_erpfmcg_test`), not SQLite — the kernel leans on
`FOR UPDATE`, CHECK constraints, and MySQL's NULL handling in unique indexes.

## Watch out for

**F-31 versus Filament.** A keyboard-driven 10-line sales order in under two
minutes is an acceptance criterion, not an aspiration, and it is the one screen
Filament's standard resource cycle will fight. Build order entry as a custom
Filament page on Livewire v4, measure against the target early, and escalate to
a hand-built screen only if it misses.
