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
| 0.3 | Full schema migrations, 11 files | ✅ | 78 tables at first, 79 now. Later migrations add what the modules needed: `stock_ledger.reverses_ledger_id` (1.6), `document_postings` (1.9), and `goods_receipt_lines.variance_reason` plus `purchase_order_lines.closed_short` (3.4) |
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
| 0.14 | Auth, roles, permissions | ✅ | Native roles plus exact decimal threshold ceilings; inactive/unassigned users cannot enter panel. `hasPermission` honours the Administrator's `*` grant — it matched exactly before, so the one role meant to do everything could do nothing. `app:create-admin` makes the first account: none is seeded, because a seeded password is a password every installation shares |
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
| 2.1 | Products + multi-level UOM | ✅ | F-01. `UomConverter` turns any unit into base and hands back a `UomSnapshot` holding the four columns a line stores, factor copied not referenced. `AddProductUom` keeps one default per direction. `base_uom` is locked on the model once `stock_ledger` has a row, so the importer and the API obey it too |
| 2.2 | Batch/expiry config per product | ✅ | F-03. `ShelfLifePolicy` computes expiry from production date and holds both thresholds; `ResolveStockBatch` is the one way inbound batches are created, so the receipt rejection cannot be walked around. Incoherent product config — expiry without batches, a percentage with no shelf life — is refused on the model |
| 2.3 | Business partners, multi-role | ✅ | Flags plus `customers()`/`suppliers()`/`principals()` scopes. A partner must hold at least one role, and a role cannot be dropped while its documents exist — deactivate instead. `invoice_exchange_days` and `payment_days` are JSON day-of-month arrays, validated (F-50) |
| 2.4 | Warehouses, rack locations, `pick_sequence` | ✅ | Type is one of the five, and the two consignment types require the partner whose side they represent while the others refuse one. `inPickOrder()` sorts the rack walk with unsequenced bays last (F-40) |
| 2.5 | Price lists, tiers, discount rules | ✅ | `PriceResolver` picks the tier's price list, the volume band the quantity reaches, and every matching rule in `sequence` order. Percentages compound, flat amounts come off the line. A rule on a parent category reaches its children. Returns a `PriceQuote` mapping onto the line columns, plus the effective percent a discount threshold is compared against (F-32) |
| 2.6 | Filament resources for all masters | ✅ | Eight resources plus relation managers for units, rack locations and prices, grouped into Catalogue / Partners / Pricing / Warehouse. `ReportsDomainRules` turns a broken domain rule into a notification instead of a 500. The units manager calls `AddProductUom` rather than saving the row itself |
| 2.7 | Excel import: products, customers, opening stock | ✅ | F-02. Template download, a check step that runs the real import inside a rolled-back transaction so the problem list is the one a commit would give, then all-or-nothing commit. Opening stock posts as one document through the kernel, facing Opening Balance Equity. **Measured: 3,000 rows in 17.3s against a 120s target** |
| 2.8 | Cost/margin hidden from sales roles | ✅ | F-70. A `view-cost` gate over the seeded `product.view_cost` permission, asked by the screens and later by the API. Average cost, stock value and margin are shown to a Sales Supervisor and hidden from a Sales Admin. Thresholds and the audit log were already in place from 0.14 and 0.11 |

---

## M3 — Purchasing `← complete`

| # | Item | Status | Notes |
|---|---|---|---|
| 3.1 | Purchase order + approval | ✅ | `DraftPurchaseOrder` snapshots the unit, factor, price and discount onto each line and totals the header from them. `PurchaseOrderStates` freezes everything from `pending_approval` on, so the figures being approved stop moving. Approval reads the approver's ceiling from `role_permissions.threshold` — the same mechanism as discount approval — and names both numbers when it refuses |
| 3.2 | Goods receipt with batch + expiry | ✅ | F-10. `DraftGoodsReceipt` records what arrived and resolves each batch; `PostGoodsReceipt` turns the accepted lines into one posted document and advances the order to partially received or closed. One PO line may arrive as several batches, and the balances stay per batch so FEFO still works |
| 3.3 | Shelf-life rejection on receipt | ✅ | F-10. Batches resolve at draft, so short-dated goods cannot even be written down as accepted — the receipt records them as a quantity rejected with a reason instead, which is what the supplier claim is built from. Goods already out of date are refused whether or not a threshold is configured |
| 3.4 | Receipt variance vs PO with reason | ✅ | F-10. Over-delivery always needs a reason — it commits money nobody approved. Closing a line short needs one too, and sets `closed_short` so an order the supplier will never complete stops waiting. A plain partial delivery needs nothing: the rest is still coming |
| 3.5 | Supplier invoice three-way match | ✅ | F-11. Quantity from the receipt, price from the order, amount from the invoice. Over-tolerance blocks until someone with `supplier_invoice.approve_variance` approves it — recorded as an approval, not a quiet edit. Posts DR 2103 + DR 1104 VAT Input / CR 2101, so GR/IR nets to zero. Tolerance is the stricter of a percentage and a flat amount, both in `company_settings` |
| 3.6 | Purchase returns | ✅ | Stock out through the kernel, DR Trade Payables / CR Inventory. It leaves at the moving average because that is what it is carried at; `unit_price` records what is being claimed back, which is a commercial figure and need not agree |
| 3.7 | Principal claims: damaged, expired, recall | ✅ | F-23. A commercial document that moves no stock — writing goods off is a separate decision, so goods can be claimed while still on the shelf awaiting collection. The statuses after `submitted` record what the principal decided, and a rejected claim goes back to draft to be argued again |

---

## M4 — Inventory operations `← complete`

| # | Item | Status | Notes |
|---|---|---|---|
| 4.1 | FEFO batch selection at picking | ✅ | F-20. `BatchPicker` allocates nearest expiry first, spreading across batches, honouring the product's `issue_method`. The batch is chosen here and not at order entry, which is why `batch_id` lives on `delivery_lines` |
| 4.2 | Shipping shelf-life filter | ✅ | F-20. Batches below `min_life_on_ship_days` are excluded before anything is offered, so they are not counted as available either — asking for more than the shippable stock says how much short it is |
| 4.3 | Batch override with recorded reason | ✅ | F-20. Staff may take a batch out of order with a reason recorded, because overriding FEFO is how stock ages out unnoticed. The override does not lift the shelf-life filter — that is a rule, not an ordering preference |
| 4.4 | Stock card with source drill-through | ✅ | F-21. Read straight from `stock_ledger`, never from a cache, with a running balance and the document number behind every line. A card for one month opens from everything before it |
| 4.5 | Stock count, frozen reference balance | ✅ | F-22. `system_qty` is copied onto each line when the count opens, so goods moving during the count change the balance without changing what is being counted against |
| 4.6 | Variance → adjustment → approval | ✅ | F-22. Closing a count raises a draft adjustment from the variances and nothing else; posting it requires `stock.adjust.approve`. A count that agrees produces no document at all |
| 4.7 | Warehouse transfers | ✅ | Ship then receive, both through the kernel, no journal — both legs hit the same inventory account. The receiving leg reads the cost off the shipping ledger rows, so moving stock cannot revalue it |
| 4.8 | Expiry alerts by remaining-life band | ✅ | F-23. Expired / 30 / 60 / 90 days, in rupiah as well as quantity — "Rp 18,400,000 expiring within 30 days" decides itself in a way that "400 units" does not. Consigned-in goods excluded by the `OwnedStock` scope |
| 4.9 | Reservation expiry cleanup job | ✅ | `stock:release-expired-reservations`, scheduled hourly, with a `--check` that reports without releasing |

---

## M5 — Sales and delivery

| # | Item | Status | Notes |
|---|---|---|---|
| 5.1 | Sales order entry, keyboard-driven | 🚧 | F-31. Custom Livewire page, fully keyboard-operable: type, ↑↓ to choose, Enter to add, cursor back in the box. Customer chosen by typing too. **Measured across 5,000 SKUs and 2,000 outlets: 25 ms customer lookup, 33 ms per line, 0.33s for ten lines and the save** — so 119.7 of the 120 seconds belong to the typist. Every written criterion is met; only the stopwatch itself is outstanding, and it needs a real sales admin |
| 5.2 | Product search < 300 ms across 5,000 SKUs | ✅ | Code, name and barcode in one query, exact code first. **Measured 28 ms worst case across 5,000 SKUs** against a 300 ms target |
| 5.3 | Credit limit + overdue hold | ✅ | F-30. Runs on submit with no way to skip it. `credit_hold` is a state, so releasing is a transition gated on `sales_order.release_hold` with the releaser recorded. `hold_detail` keeps the figures that triggered it |
| 5.4 | Tiered discounts + bonus goods | ✅ | F-32. Each tier snapshotted separately, so an invoice can be explained as 5% then 3%. Bonus lines are ordinary lines at zero revenue whose quantity still leaves the warehouse and carries its cost |
| 5.5 | Stock reservation on approval | ✅ | F-33. Reserved when the order is approved, consumed when the load ships, expired by the hourly job if neither happens |
| 5.6 | Copy-from-previous-order | ✅ | F-31. Quantities and units carry over; prices are re-quoted at today's list, because copying last month's price forward is how an old promotion becomes permanent |
| 5.7 | Picking list sorted by rack | ✅ | F-40. Sorted by `pick_sequence` with unsequenced bays last, so the picker walks the racks once |
| 5.8 | One delivery, multiple orders | ✅ | F-41. One trip, one document — refused across customers or warehouses |
| 5.9 | Proof of delivery, accepted qty | ✅ | F-42. Full, partial or refused; a refused load fails the delivery rather than pretending it arrived |
| 5.10 | Invoice from confirmed delivery | ✅ | F-42. Billed on `qty_accepted`, priced from the order line's snapshot. Cost of goods is recognised when the load leaves, revenue only when the outlet confirms — a load that comes back is not a sale. A shortfall raises a draft return for the goods on the truck |
| 5.11 | Sales returns, resellable routing | ✅ | Enforced both ways: unsellable goods are refused a normal warehouse, and resellable goods are refused the damaged one, where nothing is ever picked |

---

## M6 — Receivables and collections

*The workflow that deviates furthest from standard accounting software, and the
most common reason a new system gets rejected.*

| # | Item | Status | Notes |
|---|---|---|---|
| 6.1 | Invoice exchange batches per schedule | ✅ | F-50. `ExchangeSchedule` answers whose day it is; `AssembleInvoiceExchange` gathers their posted invoices and sets the promised payment date from the customer's own payment days, falling back to invoice terms |
| 6.2 | Printable, signable exchange receipt | 🚧 | F-50. The document and `received_by` exist and handing over transitions its invoices to `exchanged`. The printed layout waits for M8, which is blocked on the client's printer |
| 6.3 | Promised payment date → reminders | ✅ | F-50. `collections:today`, scheduled daily at 07:00: whose invoices go out today, and who promised to pay by now and has not |
| 6.4 | Postdated cheque lifecycle | ✅ | F-51. Receiving one moves the debt to 1105 Postdated Cheques Receivable and leaves cash untouched, so the cash position never counts a drawer of promises. Clearing is what makes it money |
| 6.5 | Bounced cheque reopens the invoice | ✅ | F-51. The debt goes back to the customer and the invoice returns to unpaid. `paid` is deliberately not a final state for this reason — an invoice that could not reopen would stay settled on money that never arrived |
| 6.6 | Payment allocation across invoices | ✅ | F-52. One payment across many invoices, one invoice in instalments, and the remainder credited to 2104 Customer Advances rather than booked as income — it is still the customer's money |
| 6.7 | Receivables aging by customer and salesperson | ✅ | F-53. Not yet due / 1–30 / 31–60 / 61–90 / over 90, ranked by customer and by salesperson — they answer different questions |
| 6.8 | Payables and supplier payments | ✅ | DR Trade Payables / CR Cash. No advance on this side: money paid beyond what is owed is refused, because a supplier holding our money is a conversation, not a bookkeeping entry |

---

## M7 — Accounting `← complete`

| # | Item | Status | Notes |
|---|---|---|---|
| 7.1 | Chart of accounts + mapping UI | ✅ | The screen that is the reason no account code appears in posting logic. A test proves remapping `goods_receipt.grir` sends the next receipt somewhere else, with no code change |
| 7.2 | Automatic journals from every document | ✅ | F-60. A full buy-and-sell cycle is tested to leave a journal behind every document, no drafts, every entry balancing |
| 7.3 | Manual journals for adjustments | ✅ | F-60. `JournalPoster::postManual()` names accounts directly — there is no document type to map from — but still goes through the same balance check, period lock and `journal.post` permission |
| 7.4 | General ledger | ✅ | Posted entries only, with opening balance, running balance and the source document on every line. Credit-side accounts report in the direction they carry, so a payable is not a negative debit |
| 7.5 | P&L and balance sheet with drill-down | ✅ | F-61. Every figure is a list of accounts, each drilling to its ledger and on to the documents. The balance sheet carries the year's profit itself, since it is not in a nominal account until the year closes |
| 7.6 | Inventory valuation excludes consigned-in | ✅ | Schema §11. Reconciles stock value against the mapped inventory account and reports what was excluded, so the principal's goods are visibly left out rather than silently |
| 7.7 | Period close under 2 minutes | ✅ | **Measured at 0.01s.** Refuses to close over an unposted journal or a stock figure that disagrees with the inventory account; `--force` is available once the difference is understood |

---

## M8 — Printing and reports

*PRD calls printing the most underestimated requirement and a week-one
rejection risk.*

| # | Item | Status | Notes |
|---|---|---|---|
| 8.1 | Dot matrix continuous form, 2–3 copies | 🚧 | Layouts exist at 80 columns with fixed-width padding, printed as one run separated by form feeds. Tested that no line runs past the paper and columns hold position. Still ⛔ on the client's printer model, width and copy count before the numbers mean anything |
| 8.2 | Per-client layout without code changes | ✅ | `print_templates` rows, edited on a screen. A test rewrites a layout and prints the same invoice differently with no code change. The syntax is deliberately not Blade — a layout is edited by an administrator, and Blade from the database is arbitrary PHP |
| 8.3 | A4 / PDF invoice | 🚧 | A4 renders as HTML for a browser or laser printer, from the same template mechanism. Server-side PDF needs a library; not added, because nothing yet requires emailing one |
| 8.4 | Delivery note, exchange receipt | ✅ | Both print from the same mechanism. The exchange receipt lists its invoices and is signable, which is what makes it the document the outlet keeps (F-50) |
| 8.5 | Test against a real dot matrix printer | ⛔ | Must happen **before** the first demo. The layouts exist so this test is possible, not so it is skipped — nothing about column positions is confirmed until paper comes out of the client's machine |
| 8.6 | Reports: stock card, aging, sales by product/brand/customer/salesperson, gross margin | ✅ | All five. Margin is revenue against the cost the goods actually left at, taken from the delivery lines, not a price-list guess. **Measured 0.10s worst case across 25,000 invoice lines** against a 5s NFR |

---

## M9 — Deployment and hardening

The load test at 9.7 was worth more than the number it produced. Under
twenty-five concurrent users it found three bugs that no single-threaded test
could: the deadlock retry that never ran, a lock upgrade in the moving-average
path, and a `product_costs` row created lazily on first movement. Before the
fixes, 58% of documents were lost on a contended product.


| # | Item | Status | Notes |
|---|---|---|---|
| 9.1 | Numbered migrations applied on update | ✅ | `app:update` backs up first, applies pending migrations in order, clears caches and reports the new version. The backup is not a flag: the one time it would be skipped is the one time it was needed |
| 9.2 | Remote update + offline update package | ✅ | `app:package` builds a zip carrying `vendor/` already built, so a server with no internet needs only to unpack and run `app:update`. It deliberately excludes `.env`, `storage/` and the database — those belong to the installation |
| 9.3 | Schema version recorded per installation | ✅ | `app:version` reports the last migration applied, how many are waiting, and the code commit. Non-zero exit when work is pending, so a deployment script notices. The schema doc's `schema_migrations` table was never built — Laravel's `migrations` table already is one, and a second copy is a second thing to get out of step |
| 9.4 | Daily backup, local + offsite | 🚧 | A manual backup screen: take one, download it, delete it. Scheduling and offsite copying wait on knowing the server and where offsite is (PRD §10 Q14) — the button an administrator presses before an upgrade did not need to wait for that |
| 9.5 | **Restore procedure actually tested** | ✅ | Literally. A test dumps a database holding posted documents, restores the file into a scratch schema, and compares row counts and ledger values. `--set-gtid-purged=OFF` was needed or the dump could not be restored onto the same server at all. The screen has the same check as a button |
| 9.6 | License enforcement | ✅ | **Sold, not rented: the software has no expiry and never stops working.** Two things are enforced, neither able to lock anyone out of work already done — seats, checked when a user is *activated* rather than at login, and modules that were not bought. `expires_on` is renamed `supported_until`: reported, never enforced |
| 9.7 | Load test: 25 concurrent users | ✅ | Twenty-five real processes posting goods receipts, on separate products and on one shared product. **~150–200 documents/sec, median 100–150 ms, zero lost work in both.** It found three real concurrency bugs — see below |
| 9.8 | Install + operations runbook | ✅ | [runbook.md](runbook.md) — install, update, roll back, month end, and what to run when something looks wrong. Server-specific steps are marked **[server]** and still depend on PRD §10 Q14 |

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
| First live install | Confirm the two accounting choices made for the demo: GR/IR clearing (2103) and Opening Balance Equity (3102). Both are seeder rows, not code |
| 6.1 | What share of customers use custom invoice-exchange schedules? |
| 6.4 | What proportion of payments arrive as postdated cheques? |
| P2.5, P2.6 | Is there consignment to outlets, and how is it reconciled today? |
| Phase ordering | What share of revenue is modern trade? Above 20% and it moves earlier |
| Import mapping | What is in the legacy system, and what still lives in Excel beside it? The importer is built and the templates follow the entry forms (F-02); an answer changes a column mapping, not the machinery |
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
