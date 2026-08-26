# Implementation Plan — FMCG Distributor ERP

Living document. Update the status column as work lands; do not delete completed
items, they are the record of what was built and why.

**Status:** ✅ done · 🚧 in progress · ⬜ not started · ⛔ blocked (needs an answer)

**Sources:** [PRD](prd-fmcg-distributor-erp-en.md) · [Schema](fmcg-distributor-erp-schema-en.md) · [Architecture](architecture.md) · [Known issues](known-issues.md)
Requirement codes (F-xx) refer to PRD section 7. Where the migrations and the
schema doc disagree, the migrations are authoritative and the deviation is
commented inline in the migration file.

---

## Decisions locked

| Decision | Choice | Notes |
|---|---|---|
| Database | MySQL 9.7 | Schema doc was PostgreSQL; port complete |
| Primary keys | UUIDv7, `char(36)` | Client-side ID generation for the Phase 2 offline app |
| Costing | Moving average only | `costing_method` column deliberately omitted; FIFO would be additive |
| UI | Filament v5 | Single stack; order entry to be a custom page — see Risk watch |
| Architecture | Modular monolith + actions | See [architecture.md](architecture.md) — domain folders, one posting kernel, no repositories, synchronous events |
| Deployment | Single-tenant, on-premise | One database per client, no `company_id` anywhere |

---

## M0 — Foundation

| # | Item | Status | Notes |
|---|---|---|---|
| 0.1 | MySQL configured, database created | ✅ | `.env` + `.env.example` both on MySQL |
| 0.2 | Blueprint macros: `auditable`, `money`, `qty`, `cost`, `percent` | ✅ | `app/Providers/SchemaServiceProvider.php` — enforces schema §11 "never float" by construction |
| 0.3 | Full schema migrations, 11 files | ✅ | 78 tables, 150 FKs, 4 CHECK constraints. Two later migrations add what the kernel needed: `stock_ledger.reverses_ledger_id` (1.6) and `document_postings` (1.9), taking it to 79 tables |
| 0.4 | `users` rebuilt as UUID + ERP columns | ✅ | |
| 0.5 | Up/down/up cycle verified | ✅ | `migrate` → `migrate:reset` → `migrate` clean |
| 0.6 | Constraint behaviour verified | ✅ | `stock_balances` NULL-key fix, 3 CHECKs, generated `variance` |
| 0.7 | Domain folder skeleton | ✅ | Seven bounded contexts plus `Support`; subfolders retained with placeholders |
| 0.8 | Eloquent models, all tables | ✅ | 69 ERP table models plus `User`; generated from migrations with UUIDv7, soft deletes, and relations |
| 0.9 | Decimal casts for money/qty | ✅ | `DecimalCast` exposes `brick/math` `BigDecimal`; scales inferred from migration macros |
| 0.10 | `OwnedStock` global scope | ✅ | Applied to ledger and balance models; explicit `includingConsigned()` escape hatch |
| 0.11 | Audit log observer | ✅ | Insert/update/delete old/new JSON; credentials redacted |
| 0.12 | `DocumentNumberer` service | ✅ | Transaction + `lockForUpdate()` on the configured branch/type/period row. Rolls the prefix into a new period by itself, locking the previous period's row first — MySQL keeps NULLs distinct in the unique key, so branch-less sequences would otherwise race |
| 0.13 | Filament v5 install + panel | ✅ | Filament 5.7 admin panel at `/admin` |
| 0.14 | Auth, roles, permissions | ✅ | Native roles plus exact decimal threshold ceilings; inactive/unassigned users cannot enter panel. `hasPermission` honours the Administrator's `*` grant — it matched exactly before, so the one role meant to do everything could do nothing |
| 0.15 | Seeders: COA, roles, settings, journal mappings | ✅ | Idempotent; verified twice against MySQL |
| 0.16 | Model factories + test harness | ✅ | PHPUnit against MySQL (`psr_erpfmcg_test`); core master/inventory factories and M0 feature tests. SQLite was dropped: it rejects the CHECK constraints and has different `FOR UPDATE` and NULL-in-unique-index semantics, so it tested an engine this product never runs on |

---

## M1 — Posting kernel `← complete`

Schema §11: *exactly one code path writes stock.* Every document that moves
goods calls the same service — ledger, balances, average cost, journal — or the
module that skips a step is the one that corrupts inventory.

| # | Item | Status | Notes |
|---|---|---|---|
| 1.1 | `StockPosting` service | ✅ | Sole stock writer; ledger → balance → cost → journal in one transaction |
| 1.2 | Moving average under `lockForUpdate` | ✅ | Owned product cost row serializes postings; outbound issues use the locked average |
| 1.3 | Concurrency test for 1.2 | ✅ | Two real MySQL processes converge to the correct quantity, value, and average |
| 1.4 | `JournalPoster` driven by `journal_mappings` | ✅ | Sole writer of `journal_entries`/`journal_lines`; posting keys resolve per client, an unmapped key refuses to post. `StockJournalRules` holds the entry *shape* per `ref_type` — transfers post nothing, consigned-in goods post nothing (F-60) |
| 1.5 | Document state machine base | ✅ | `DocumentStates` + `HasDocumentState`: declared transitions, per-transition permissions, frozen statuses that refuse edits and deletes. Applied to `journal_entries`; each module declares its own under `States/` as it lands |
| 1.6 | Reversal by counter-entry | ✅ | `StockReversal` mirrors every ledger row of a document through `StockPosting`, so the counter-journal falls out of the sign flip. Reversals back out their own value, never today's average. `stock_ledger.reverses_ledger_id` is uniquely indexed, so a second reversal is impossible rather than merely checked (F-10) |
| 1.7 | Accounting period lock enforcement | ✅ | `PeriodGuard` checked by both `StockPosting` and `JournalPoster`, so neither stock nor a manual journal can reach a closed month. A month with no row is open — locking is a decision, not a default. Closing and reopening run through the state machine on `accounting.period.close` (F-60) |
| 1.8 | `stock:rebuild-balances` command | ✅ | `BalanceRebuilder` compares and corrects; `--check` is read-only and exits non-zero on drift, so it works as a scheduled health check. Consigned balances are rebuilt, not discarded — every query drops the `OwnedStock` scope (F-21) |
| 1.9 | Idempotent posting | ✅ | `postDocument()` claims the document in `document_postings` before posting any line; the unique key decides a race rather than a check-then-write. Two real processes submitting one document post it once. A failed posting rolls its claim back, so a corrected retry works |

---

## M2 — Masters

| # | Item | Status | Notes |
|---|---|---|---|
| 2.1 | Products + multi-level UOM | ⬜ | F-01; base unit locked once movement history exists |
| 2.2 | Batch/expiry config per product | ⬜ | F-03; shelf life, receipt and shipping thresholds |
| 2.3 | Business partners, multi-role | ⬜ | Supplier/customer/principal flags on one record |
| 2.4 | Warehouses, rack locations, `pick_sequence` | ⬜ | |
| 2.5 | Price lists, tiers, discount rules | ⬜ | Sequential compounding, never summed |
| 2.6 | Filament resources for all masters | ⬜ | Thin: form + table, logic stays in actions |
| 2.7 | Excel import: products, customers, opening stock | ⬜ | F-02; preview + problem rows, all-or-nothing, 3,000 rows < 2 min |
| 2.8 | Cost/margin hidden from sales roles | ⬜ | F-70; field-level permission |

---

## M3 — Purchasing

| # | Item | Status | Notes |
|---|---|---|---|
| 3.1 | Purchase order + approval | ⬜ | |
| 3.2 | Goods receipt with batch + expiry | ⬜ | F-10; one PO line may split across batches |
| 3.3 | Shelf-life rejection on receipt | ⬜ | F-10; `min_life_on_receipt_pct` **blocks**, server-side, not a warning |
| 3.4 | Receipt variance vs PO with reason | ⬜ | F-10 |
| 3.5 | Supplier invoice three-way match | ⬜ | F-11; over-tolerance blocks until supervisor approves |
| 3.6 | Purchase returns | ⬜ | |
| 3.7 | Principal claims: damaged, expired, recall | ⬜ | Feeds from expiry alerts (F-23) |

---

## M4 — Inventory operations

| # | Item | Status | Notes |
|---|---|---|---|
| 4.1 | FEFO batch selection at picking | ⬜ | F-20; batch decided at picking, not order entry |
| 4.2 | Shipping shelf-life filter | ⬜ | F-20; below-threshold batches never offered |
| 4.3 | Batch override with recorded reason | ⬜ | F-20 |
| 4.4 | Stock card with source drill-through | ⬜ | F-21 |
| 4.5 | Stock count, frozen reference balance | ⬜ | F-22; warehouse keeps operating during the count |
| 4.6 | Variance → adjustment → approval | ⬜ | F-22 |
| 4.7 | Warehouse transfers | ⬜ | |
| 4.8 | Expiry alerts by remaining-life band | ⬜ | F-23; rupiah value, not just quantity |
| 4.9 | Reservation expiry cleanup job | ⬜ | Schema §11; unprocessed orders otherwise lock stock forever |

---

## M5 — Sales and delivery

| # | Item | Status | Notes |
|---|---|---|---|
| 5.1 | Sales order entry, keyboard-driven | ⬜ | **F-31: 10 lines under 2 minutes** — see Risk watch |
| 5.2 | Product search < 300 ms across 5,000 SKUs | ⬜ | Code, name, barcode, as you type |
| 5.3 | Credit limit + overdue hold | ⬜ | F-30; automatic on save, release is role-gated and logged |
| 5.4 | Tiered discounts + bonus goods | ⬜ | F-32; bonus leaves stock at zero revenue, still carries cost |
| 5.5 | Stock reservation on approval | ⬜ | F-33 |
| 5.6 | Copy-from-previous-order | ⬜ | F-31 |
| 5.7 | Picking list sorted by rack | ⬜ | F-40 |
| 5.8 | One delivery, multiple orders | ⬜ | F-41 |
| 5.9 | Proof of delivery, accepted qty | ⬜ | F-42 |
| 5.10 | Invoice from confirmed delivery | ⬜ | F-42; shortfall raises return or adjustment |
| 5.11 | Sales returns, resellable routing | ⬜ | Non-resellable goes to the damaged warehouse |

---

## M6 — Receivables and collections

*The workflow that deviates furthest from standard accounting software, and the
most common reason a new system gets rejected.*

| # | Item | Status | Notes |
|---|---|---|---|
| 6.1 | Invoice exchange batches per schedule | ⬜ | F-50; `invoice_exchange_days` per customer |
| 6.2 | Printable, signable exchange receipt | ⬜ | F-50 |
| 6.3 | Promised payment date → reminders | ⬜ | F-50 |
| 6.4 | Postdated cheque lifecycle | ⬜ | F-51; held → deposited → cleared → bounced, not cash until cleared |
| 6.5 | Bounced cheque reopens the invoice | ⬜ | F-51; plus an alert |
| 6.6 | Payment allocation across invoices | ⬜ | F-52; remainder held as advance |
| 6.7 | Receivables aging by customer and salesperson | ⬜ | F-53 |
| 6.8 | Payables and supplier payments | ⬜ | |

---

## M7 — Accounting

| # | Item | Status | Notes |
|---|---|---|---|
| 7.1 | Chart of accounts + mapping UI | ⬜ | Per-client configuration, not code |
| 7.2 | Automatic journals from every document | ⬜ | F-60; via `JournalPoster` (1.4) |
| 7.3 | Manual journals for adjustments | ⬜ | F-60 |
| 7.4 | General ledger | ⬜ | |
| 7.5 | P&L and balance sheet with drill-down | ⬜ | F-61 |
| 7.6 | Inventory valuation excludes consigned-in | ⬜ | Schema §11; `owner_id IS NULL` or the balance sheet is overstated |
| 7.7 | Period close under 2 minutes | ⬜ | NFR |

---

## M8 — Printing and reports

*PRD calls printing the most underestimated requirement and a week-one
rejection risk.*

| # | Item | Status | Notes |
|---|---|---|---|
| 8.1 | Dot matrix continuous form, 2–3 copies | ⛔ | Needs the client's actual printer model and copy count |
| 8.2 | Per-client layout without code changes | ⬜ | Template data, not forked code |
| 8.3 | A4 / PDF invoice | ⬜ | |
| 8.4 | Delivery note, exchange receipt | ⬜ | |
| 8.5 | Test against a real dot matrix printer | ⛔ | Must happen **before** the first demo |
| 8.6 | Reports: stock card, aging, sales by product/brand/customer/salesperson, gross margin | ⬜ | Under 5 seconds across a year of data |

---

## M9 — Deployment and hardening

| # | Item | Status | Notes |
|---|---|---|---|
| 9.1 | Numbered migrations applied on update | ⬜ | Mandatory from first release — every client is a separate installation |
| 9.2 | Remote update + offline update package | ⬜ | Some clients have no external access |
| 9.3 | Schema version recorded per installation | ⬜ | |
| 9.4 | Daily backup, local + offsite | ⬜ | |
| 9.5 | **Restore procedure actually tested** | ⬜ | Not merely documented |
| 9.6 | License enforcement | ⬜ | `licenses` table exists, no enforcement designed yet |
| 9.7 | Load test: 25 concurrent users | ⬜ | |
| 9.8 | Install + operations runbook | ⬜ | |

---

## Phase 2 — FMCG differentiators (after MVP)

Not started. Listed so scope stays visible.

| # | Item | Status | Notes |
|---|---|---|---|
| P2.1 | Salesperson app: offline orders, routes, catalogue | ⬜ | Android 8+, under 30 MB; needs the API layer |
| P2.2 | REST API for mobile sync | ⬜ | Same actions as the UI, different entry point |
| P2.3 | Visit routes + commission | ⬜ | **Not in the schema yet** — new tables required |
| P2.4 | Principal programs: rebates, buy-get, bonus | ⬜ | Tables exist, no logic |
| P2.5 | Outbound consignment to outlets | ⬜ | Transfer to virtual warehouse, settle periodically |
| P2.6 | Inbound consignment from principals | ⬜ | Via `owner_id` on the ledger |
| P2.7 | Sell-in / sell-out reporting per principal | ⬜ | Export formats per principal |
| P2.8 | Multi-warehouse and multi-branch UI | ⬜ | Columns already exist throughout |

---

## Blocked on client answers

These cannot be finished from the documents alone. PRD section 10 is the
interview script; none of it has been answered yet.

| Blocks | Question |
|---|---|
| 8.1, 8.5 | Dot matrix or laser? Which model, how many copies? |
| Costing (locked provisionally) | Does the client's accountant use moving average or FIFO? Irreversible once transactions exist |
| 6.1 | What share of customers use custom invoice-exchange schedules? |
| 6.4 | What proportion of payments arrive as postdated cheques? |
| P2.5, P2.6 | Is there consignment to outlets, and how is it reconciled today? |
| Phase ordering | What share of revenue is modern trade? Above 20% and it moves earlier |
| 2.7 | What is in the legacy system, and what still lives in Excel beside it? |
| 9.x | What is the current server, who administers it, Windows or Linux? |

---

## Risk watch

**F-31 versus Filament.** A keyboard-driven 10-line order in under 2 minutes is
an acceptance criterion, not an aspiration, and the standard Filament resource
cycle is the one place that will fight it. Plan: build order entry (5.1) as a
custom Filament page on Livewire v4, measure it against the target with a real
sales admin, and escalate to a hand-built Inertia screen only if it misses.
Measure before optimising, but measure early — this is the requirement that
decides whether the system gets used or rejected.

**Scope creep from the first client.** Per-client variation belongs in data —
`journal_mappings`, print templates, `role_permissions.threshold` — never in
forked code. The moment a client request needs a code change, it is
customisation, not a product feature.

**Concurrency in costing.** Item 1.3 is not optional. Moving average computed
without a row lock is wrong the first time two receipts post at once, and the
symptom surfaces months later in an audit.
