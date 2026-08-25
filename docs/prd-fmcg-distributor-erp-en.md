# PRD — FMCG Distributor ERP

Version 0.1 (draft) · Status: not yet market-validated

Anything marked `[ASSUMPTION]` is an educated guess based on common industry patterns, not a field finding. The top priority before writing any code is validating these against 3–5 real distributors. The interview questions are in section 10.

---

## 1. Summary

An ERP system for mid-sized FMCG distributors in Indonesia, covering the full cycle from principal goods receipt through to collected payment. Deployed on the client's own server (not SaaS), with emphasis on the three pain points distributors complain about most: stock variance, overdue receivables, and slow monthly closing.

The core differentiator against general accounting software: this system understands how FMCG distribution actually works — principal programs, rebates, invoice exchange schedules, postdated cheques, batch and expiry tracking, consignment to outlets, and field salespeople taking orders without signal.

---

## 2. Problem

### Current state `[ASSUMPTION]`

Mid-sized FMCG distributors generally sit in one of three situations:

**Excel plus paper ledgers.** Stock recorded by hand, receivables tracked in a separate spreadsheet, financial statements rebuilt from scratch every month. Works fine at low volume, collapses past roughly 500 SKUs or 200 customers.

**General accounting software** (Accurate, Zahir, Jurnal). The bookkeeping is sound, but the software has no concept of distribution. Principal programs get calculated manually in Excel, sales routes don't exist, invoice exchange lives in a notebook, and batch expiry is untracked.

**Legacy vendor software.** Built by a local developer 10–15 years ago, runs on Windows, and the vendor is now unreachable or steadily raising fees. The data has accumulated and nobody dares to migrate.

### Quantified losses `[ASSUMPTION]`

| Problem | Typical impact |
|---|---|
| Stock variance at physical count | 1–3% of inventory value, cause unknown |
| Expiring goods caught too late | Direct write-off, principal claim window missed |
| Receivables not collected on schedule | Cash flow squeezed, principal credit line exhausted |
| Manual principal program reconciliation | Rebates under-claimed or miscalculated |
| Month-end close takes 3–4 weeks | Decisions made on last month's data |
| Salespeople ordering via WhatsApp | Input errors, inconsistent pricing, no audit trail |

### What actually triggers a system change `[ASSUMPTION]`

Distributors rarely switch ERP because "the features are better." The real triggers are usually:

- A principal starts requiring regular sell-out reports in a specific format
- A tax audit exposes bookkeeping that can't be defended
- The incumbent vendor shuts down or raises prices sharply
- Stock or cash leakage is discovered and can't be traced
- Expansion to a second branch that the old system can't handle

---

## 3. Target users

### Company profile `[ASSUMPTION]`

- Annual revenue IDR 20–200 billion
- 500–5,000 active SKUs, 3–15 principals
- 200–2,000 active outlets
- 1–5 warehouses or branches
- 15–60 staff, 5–25 concurrent system users
- Mostly general trade (shops, wholesalers, kiosks), some modern trade

### User roles

**Owner / director.** Not a daily user. Needs four numbers: cash position, receivables aging, dead stock value, and margin per principal. If it takes more than three clicks to reach any of those, they'll stop opening the system and go back to asking staff.

**Sales admin.** The heaviest user. Enters sales orders all day from field orders, phone calls, and WhatsApp. Their only success metric is seconds per order. If it's slower than their old system, they will reject it.

**Warehouse supervisor.** Receives goods, manages picking, runs stock counts. Often not comfortable with computers. Needs large screens, few buttons, and validation that prevents mistakes rather than error messages after the fact.

**Finance / collections staff.** Manages invoice exchange, postdated cheques, collection schedules, bank reconciliation. Their workflow deviates furthest from standard accounting software, and is the most common reason a new system gets rejected.

**Field salesperson.** Takes orders at the outlet, frequently without signal. Needs a mobile app that works offline, stays light, and syncs automatically once connected. If it's slow or eats data, they'll go back to WhatsApp.

**Driver.** Carries delivery notes, collects proof of delivery. Interaction must be minimal: open the delivery list, photo, signature, done.

**Principal relations staff.** Prepares sell-in and sell-out reports, files damage and expiry claims, calculates rebates. Currently all manual in Excel.

---

## 4. Goals and success metrics

### For the client

| Metric | Baseline `[ASSUMPTION]` | 6-month target |
|---|---|---|
| Stock variance against inventory value | 1–3% | Below 0.5% |
| Average receivables age | 55–70 days | Down 15 days |
| Month-end close time | 21–30 days | 7 days |
| Sales order to printed delivery note | 2–4 hours | Under 30 minutes |
| Annual expired goods value | Not measured | Measured, down 40% |
| Principal rebates successfully claimed | Not measured | 95% of entitlement |

### For the product business

`[ASSUMPTION]` — adjust to team capacity and funding:

- 3 paying clients within the first 12 months, with the first as a discounted development partner
- Implementation time per client dropping from 3 months (first client) to 4 weeks (fifth client)
- Annual maintenance renewal rate above 90%

---

## 5. Decisions already made

The following are settled and act as design constraints:

**Deployed on client servers**, not shared SaaS. Consequence: no `company_id` in the schema; instead, a numbered database migration system and a remote update mechanism are mandatory from the first release.

**Batch and expiry tracking**, with FEFO as the default issue method. Per-unit serial numbers are not supported.

**Two-way consignment is supported**: principal goods held in the client's warehouse, and client goods placed with outlets.

**Costing method: moving average** `[ASSUMPTION]` — needs confirmation with the prospective client's accountant, since some use FIFO and changing this after transactions exist is not feasible.

---

## 6. Scope

### Phase 1 — MVP (target 4 months)

Enough to run daily operations for a single-warehouse distributor, and enough to demo.

- Masters: multi-UOM products, brands, categories, multi-role business partners, warehouses, price lists
- Bulk import from Excel for products, customers, and opening stock balances
- Purchasing: PO, goods receipt with batch and expiry, supplier invoice, purchase return
- Inventory: stock ledger, per-batch balances, FEFO, transfers, physical count, adjustments
- Sales: sales order with credit limit check, picking, delivery note, invoice, sales return
- Finance: receivables, payables, invoice exchange, postdated cheques, payments, invoice allocation
- Accounting: chart of accounts, automatic journals from every document, general ledger, P&L, balance sheet
- Users, roles, and permissions
- Print outputs: invoice (dot matrix and A4), delivery note, invoice exchange receipt
- Reports: stock card, receivables aging, sales by product/brand/customer/salesperson, gross margin

### Phase 2 — FMCG differentiators (3 months)

This is what makes it more than accounting software.

- Salesperson app: visit routes, offline order taking, catalogue, stock check, outlet history
- Principal programs: tiered rebates, buy-get promotions, bonus goods
- Outbound consignment to outlets, with periodic sales settlement
- Inbound consignment from principals
- Sell-in and sell-out reporting per principal with export formats
- Damage and expiry claims to principals
- Expiry alerts and stock aging reports
- Multi-warehouse and multi-branch

### Phase 3 — Scale (after 3 clients are live)

- Driver app with photo and location-stamped proof of delivery
- Load planning and delivery routing
- Automatic salesperson commission
- Modern trade: electronic PO, listing and promo deductions, dedicated reconciliation
- e-Faktur / Coretax integration
- Owner dashboard on mobile

### Explicitly out of scope

Written down deliberately, so it doesn't creep in through client requests:

- Manufacturing or production
- Payroll and HR
- Retail point of sale
- Per-unit serial numbers
- Multi-currency
- Consolidated accounting across legal entities

---

## 7. Functional requirements

Format: as a [role], I need [capability], so that [outcome]. Followed by testable acceptance criteria.

### 7.1 Product master

**F-01** As an admin, I need to register products with multiple nested units of measure, so I can buy by carton and sell by pack or piece.

- Each product has one base unit and any number of derived units with conversion factors
- All stock is stored in the base unit; conversion happens at input and display
- Base unit is permanently locked once the product has stock movement history
- Each unit can carry its own barcode

**F-02** As an admin, I need to import thousands of products from Excel, so I don't have to enter them one by one during onboarding.

- A downloadable Excel template exists, with columns matching the entry form exactly
- The system shows a preview and a list of problem rows before committing
- Import is all-or-nothing; no partial imports
- 3,000 rows complete in under 2 minutes

**F-03** As an admin, I need to flag products as batch-tracked with a shelf life, so expiry dates are calculated automatically at receipt.

- Shelf life entered in days; expiry auto-fills from production date
- Minimum remaining life on receipt entered as a percentage
- Minimum remaining life on shipment entered in days

### 7.2 Goods receipt

**F-10** As a warehouse supervisor, I need to receive goods against a PO while capturing batch and expiry, so stock is traceable to batch level.

- Received quantity may differ from the PO, with a mandatory reason
- One PO line may be split across multiple batches
- Goods below the remaining-life threshold are rejected by the system, not merely flagged
- Posted receipts cannot be edited, only reversed with a counter-journal

**F-11** As finance staff, I need to match supplier invoices against PO and receipt, so we don't pay for goods we never received.

- Variance above a tolerance threshold blocks posting until approved by a supervisor
- One invoice may cover multiple receipts

### 7.3 Inventory

**F-20** As a warehouse supervisor, I need the system to select batches automatically at picking based on nearest expiry, so older stock leaves first.

- Batch is determined at picking, not at order entry
- Staff may override the system's batch choice, with the reason recorded
- Batches below the shipping remaining-life threshold do not appear as options

**F-21** As an owner, I need a stock card that drills through to source documents, so every variance can be explained.

- Every stock card line links to its source document
- Balances can be recomputed from the ledger at any time to verify the cache

**F-22** As a warehouse supervisor, I need to run stock counts without halting operations, so we don't have to close the warehouse for a day.

- Counts can be scheduled by category or by rack location
- The system freezes a reference balance at the moment the count is created
- Variances produce an adjustment requiring approval before affecting stock

**F-23** As warehouse staff, I need a list of goods approaching expiry, so they can be pushed for sale or claimed against the principal.

- Grouped by remaining-life band
- Shows rupiah value, not just quantity
- Can generate a principal claim document directly

### 7.4 Sales

**F-30** As a sales admin, I need orders held automatically when a customer exceeds their limit or has overdue invoices, so we stop adding to bad debt.

- The check runs automatically when the order is saved
- The hold reason is shown clearly along with the numbers
- Only specific roles can release a hold, and the action is logged

**F-31** As a sales admin, I need to complete a 10-line order in under 2 minutes, so this isn't slower than the old way.

- Product search runs as you type, matching code, name, and barcode
- Price, unit, and discount auto-fill from the price list and customer tier
- The entire form is keyboard-operable without touching the mouse
- A copy-from-previous-order function exists per customer

**F-32** As an admin, I need tiered discounts and bonus goods, so we can follow principal promotion schemes.

- Tiered discounts compound sequentially rather than summing
- Bonus goods leave stock at zero sales value but still carry cost
- Discounts above a threshold require approval

**F-33** As an admin, I need stock reserved as soon as an order is approved, so the same goods aren't sold twice.

- Reservation releases when the delivery note is issued or the order is cancelled
- Reservations expire and are cleaned up automatically

### 7.5 Delivery

**F-40** As a warehouse supervisor, I need picking lists sorted by rack location, so staff don't walk back and forth.

**F-41** As an admin, I need to issue one delivery note covering multiple orders for the same customer, so one trip doesn't need three documents.

**F-42** As finance staff, I need invoices generated from confirmed deliveries, so we never bill for goods that haven't arrived.

- Invoice quantity follows what the outlet actually received, not what was shipped
- Receipt shortfalls generate an automatic return or invoice adjustment

### 7.6 Receivables and collections

**F-50** As collections staff, I need to assemble invoice exchange batches per customer on their schedule, so collections match how the outlet operates.

- Exchange day and payment day are stored per customer
- The system assembles the invoices falling on each day's exchange schedule
- A printable, signable exchange receipt is issued
- The promised payment date is recorded and drives reminders

**F-51** As finance staff, I need postdated cheques tracked separately from cash, so the cash position isn't misleading.

- Cheques carry status: held, deposited, cleared, bounced
- Not recognised as cash until cleared
- A bounced cheque returns the invoice to unpaid and raises an alert

**F-52** As finance staff, I need to allocate one payment across several invoices, so it matches how outlets actually settle.

- One payment may close many invoices, and one invoice may be paid in instalments
- Unallocated remainder is held as an advance

**F-53** As an owner, I need receivables aging by customer and by salesperson, so I know where the problems are.

### 7.7 Accounting

**F-60** As finance staff, I need every operational document to generate journals automatically, so nothing is entered twice.

- Account mapping is configurable per document type
- Manual journals remain available for adjustments
- Periods can be locked; once locked, no backdated transactions are permitted

**F-61** As an owner, I need P&L and balance sheet figures that drill down to source transactions, so I can investigate anything that looks wrong.

### 7.8 Access control

**F-70** As an owner, I need cost and margin hidden from salespeople, so sensitive information doesn't leak.

- Permissions are configurable per role, down to field level for pricing data
- Discount approval and credit hold release have tiered thresholds
- All sensitive actions are written to an immutable audit log

---

## 8. Non-functional requirements

### Environment

`[ASSUMPTION]` — verify on site at prospective clients:

- Server: office PC or mini server, Linux or Windows, 8–16 GB RAM
- Some clients may already run a VPS; the product must work on both
- Local network, with some branch users connecting over the internet
- 5–25 concurrent users

### Performance

- Product search returns results in under 300 ms across 5,000 SKUs
- Sales order save completes in under 1 second
- Stock and receivables reports return in under 5 seconds across a year of data
- Month-end close runs in under 2 minutes

### Printing

The most underestimated requirement, and a common cause of rejection.

- Invoices must print to dot matrix continuous form in 2–3 copies, since most distributors still rely on this for invoice exchange
- Print layouts must be customisable per client without code changes
- A4 and PDF versions also required

### Data availability

- Automatic daily backup to local storage and to an offsite location
- A restore procedure that has actually been tested, not merely documented
- The system stays readable during internet outages, since the server is on-premises

### Salesperson app (phase 2)

- Fully functional without signal, syncing when a connection is available
- Under 30 MB, low data consumption
- Runs on Android 8 and above, since field devices are typically low to mid range

### Version updates

- Numbered database migrations, applied automatically during updates
- Executable remotely, or via a self-contained update package for clients without external access
- Each installation records its current schema version

---

## 9. Critical flows

Four flows that must be smooth, because they run dozens of times a day.

**Principal goods receipt.** PO → goods arrive → physical check against PO → capture batch and expiry → system rejects insufficient remaining life → putaway to rack → stock increases → inventory and payable journals post.

**Order to delivery.** Salesperson takes the order at the outlet → syncs to head office → system checks credit limit and overdue invoices → order approved, stock reserved → picking list sorted by rack → batch selected by FEFO → delivery note → loading → proof of delivery → invoice issued.

**Collection to settlement.** Invoice enters the customer's exchange schedule → exchange receipt printed → promised payment date recorded → collection visit → cash, transfer, or cheque received → cheque awaits clearing → payment allocated to invoices → receivable reduced.

**Consignment settlement.** Goods shipped to the outlet as a warehouse transfer, not a sale → staff periodically record what sold → settlement document issued → invoice generated from the settlement → only then do sales, cost of goods, and receivable appear.

---

## 10. Assumptions requiring validation

Take this list to interviews with 3–5 distributors. The answers will change this document, and that's the point.

### On the problem

1. Of everything that causes friction, what are the top three? Don't offer options — let them name their own.
2. What was the variance at the last stock count, and was the cause identified?
3. How long from month-end to finished financial statements?
4. What was the value of expired goods last year? If they don't know the number, that itself is a finding.

### On the current state

5. What are they using now, and what parts still live in Excel outside that system?
6. What would actually make them switch systems?
7. Have they failed at a system migration before? Why did it fail?

### On operations

8. How do salespeople take orders today? What share of orders comes through WhatsApp?
9. Exactly how does invoice exchange work, and what share of customers use custom schedules?
10. What proportion of payments arrive as postdated cheques?
11. Is there consignment to outlets? How many locations, and how is it reconciled today?
12. What forms do principal programs take, and who calculates them?
13. What share of revenue is modern trade? Above 20% and this needs to move to an earlier phase.

### On technical constraints

14. What is the current server, and who administers it?
15. How many people will be using it simultaneously at peak?
16. Is the invoice printer dot matrix or laser? How many copies?
17. Does the warehouse use barcode scanners? Which model?

### On commercials

18. What do they currently spend annually on software plus the people who maintain it?
19. Does a one-time licence plus annual maintenance fit better, or a monthly subscription?
20. Who makes the purchase decision, and who can block it?

---

## 11. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Legacy data migration goes badly | Implementation slips by months, client loses confidence | Build the import tooling in phase 1, not later; target opening balances only, not full history |
| Sales admin rejects it as slower | System bought but never used | Make order entry speed a measured acceptance criterion, not an aspiration |
| Invoice layout doesn't fit the client's printer | Rejected in week one | Test against a real dot matrix printer before the first demo |
| Scope expands to match the first client's requests | Product becomes bespoke and unsellable | Draw a hard line between product features and paid customisation |
| Costing miscalculated under concurrent transactions | Margin reporting wrong, surfaces during audit | Compute within the same database transaction using row locks; load-test early |
| Many installations on divergent versions become unmaintainable | Support cost grows with each client | Automated migrations and version tracking from the first release |
| First client isn't representative of the market | Product too narrow to resell | Validate against 3–5 distributors before locking phase 1 scope |

---

## 12. Rough release plan

`[ASSUMPTION]` — depends on team size; the figures below assume 2–3 full-time developers.

| Stage | Duration | Output |
|---|---|---|
| Market validation | 3–4 weeks | 5 distributor interviews, PRD v1.0 |
| Foundation | 4 weeks | Schema, auth, permissions, master data, Excel import |
| Core flows | 8 weeks | Purchasing, inventory, sales, delivery |
| Finance | 4 weeks | Receivables, payables, invoice exchange, cheques, auto-journals |
| Hardening | 4 weeks | Reports, print layouts, load testing, documentation |
| First client pilot | 8 weeks | Running in parallel with the old system, fixes |
| Phase 2 | 12 weeks | Salesperson app, principal programs, consignment |

Total to first client fully live: roughly 8–9 months.

---

## 13. Open questions

- Which costing method do prospective clients use — moving average or FIFO?
- Is support needed for multiple legal entities within one installation?
- Which phase does modern trade belong in? Depends on its revenue share at the first client.
- Commercial model: one-time licence or subscription?
- Who handles implementation and on-site training at the client?
