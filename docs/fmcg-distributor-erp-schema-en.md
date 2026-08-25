# FMCG Distributor ERP — Database Schema

PostgreSQL. Version 0.2 — revised for three decisions: single-tenant on-premise deployment, batch and expiry tracking, and two-way consignment.

Every table carries `created_at`, `created_by`, `updated_at`, `updated_by`, and `deleted_at` for soft deletes. These are omitted below to keep the definitions readable — assume they exist everywhere.

Conventions: all quantities and monetary amounts use `numeric`, never `float`. Statuses use constrained text values, never free-form strings. A small number of foreign keys reference tables defined later in the file; those are added in the deferred constraints block at the end.

---

## 0. System and organisation

No `company_id` anywhere. Each client gets their own database, so tenant scoping is unnecessary and would only add weight to every query.

```sql
-- Single row. Holds installation-wide configuration.
CREATE TABLE company_settings (
  id                    uuid PRIMARY KEY,
  legal_name            text NOT NULL,
  tax_id                text,
  address               text,
  costing_method        text NOT NULL DEFAULT 'moving_average',  -- 'moving_average' | 'fifo'
  logo_url              text,
  CONSTRAINT single_row CHECK (id = '00000000-0000-0000-0000-000000000001')
);

CREATE TABLE branches (
  id                    uuid PRIMARY KEY,
  code                  text NOT NULL UNIQUE,
  name                  text NOT NULL,
  address               text,
  active                boolean NOT NULL DEFAULT true
);

CREATE TABLE users (
  id                    uuid PRIMARY KEY,
  branch_id             uuid REFERENCES branches,
  email                 text NOT NULL UNIQUE,
  password_hash         text NOT NULL,
  full_name             text NOT NULL,
  active                boolean NOT NULL DEFAULT true,
  last_login_at         timestamptz
);

CREATE TABLE roles (
  id                    uuid PRIMARY KEY,
  name                  text NOT NULL UNIQUE
);

CREATE TABLE role_permissions (
  role_id               uuid NOT NULL REFERENCES roles,
  permission            text NOT NULL,   -- 'sales_order.approve', 'product.view_cost'
  threshold             numeric(18,2),   -- optional ceiling, e.g. max discount percent
  PRIMARY KEY (role_id, permission)
);

CREATE TABLE user_roles (
  user_id               uuid NOT NULL REFERENCES users,
  role_id               uuid NOT NULL REFERENCES roles,
  PRIMARY KEY (user_id, role_id)
);

-- Never generate document numbers with COUNT(*). Lock this row instead.
CREATE TABLE number_sequences (
  id                    uuid PRIMARY KEY,
  branch_id             uuid REFERENCES branches,
  document_type         text NOT NULL,   -- 'PO', 'GR', 'SO', 'DO', 'SI'
  prefix                text NOT NULL,   -- 'SO/{BRANCH}/{YY}{MM}/'
  period                text NOT NULL,   -- '2026-08', for monthly reset
  last_number           bigint NOT NULL DEFAULT 0,
  UNIQUE (branch_id, document_type, period)
);

CREATE TABLE audit_logs (
  id                    bigserial PRIMARY KEY,
  user_id               uuid,
  table_name            text NOT NULL,
  record_id             uuid NOT NULL,
  action                text NOT NULL,   -- 'insert' | 'update' | 'delete' | 'approve' | 'release_hold'
  old_data              jsonb,
  new_data              jsonb,
  created_at            timestamptz NOT NULL DEFAULT now()
);

-- Required because every client runs a separate installation.
CREATE TABLE schema_migrations (
  version               bigint PRIMARY KEY,
  name                  text NOT NULL,
  applied_at            timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE licenses (
  id                    uuid PRIMARY KEY,
  license_key           text NOT NULL,
  expires_on            date,
  max_users             int,
  enabled_modules       text[],
  issued_at             timestamptz NOT NULL DEFAULT now()
);
```

---

## 1. Business partners

One table, multiple roles. A company can be a principal and a supplier at once, or a supplier and a customer.

```sql
CREATE TABLE business_partners (
  id                    uuid PRIMARY KEY,
  code                  text NOT NULL UNIQUE,
  name                  text NOT NULL,
  tax_id                text,
  contact_person        text,
  phone                 text,

  is_supplier           boolean NOT NULL DEFAULT false,
  is_customer           boolean NOT NULL DEFAULT false,
  is_principal          boolean NOT NULL DEFAULT false,

  -- customer-only fields
  tier_id               uuid,
  salesperson_id        uuid,
  territory_id          uuid,
  credit_limit          numeric(18,2) DEFAULT 0,
  payment_terms_days    int DEFAULT 0,
  on_hold               boolean NOT NULL DEFAULT false,
  hold_reason           text,
  invoice_exchange_days int[],          -- {1,15} = 1st and 15th of month
  payment_days          int[],

  active                boolean NOT NULL DEFAULT true
);

CREATE TABLE partner_addresses (
  id                    uuid PRIMARY KEY,
  partner_id            uuid NOT NULL REFERENCES business_partners,
  address_type          text NOT NULL,   -- 'billing' | 'shipping'
  recipient_name        text,
  address               text NOT NULL,
  city                  text,
  phone                 text,
  latitude              numeric(10,7),
  longitude             numeric(10,7),
  is_default            boolean NOT NULL DEFAULT false
);

CREATE TABLE customer_tiers (
  id                    uuid PRIMARY KEY,
  name                  text NOT NULL UNIQUE,   -- 'wholesale', 'retail', 'modern trade'
  price_list_id         uuid
);

CREATE TABLE territories (
  id                    uuid PRIMARY KEY,
  parent_id             uuid REFERENCES territories,
  name                  text NOT NULL
);

CREATE TABLE salespeople (
  id                    uuid PRIMARY KEY,
  user_id               uuid REFERENCES users,
  code                  text NOT NULL UNIQUE,
  name                  text NOT NULL,
  territory_id          uuid REFERENCES territories,
  sales_type            text,            -- 'canvas' | 'taking_order' | 'motorist'
  active                boolean NOT NULL DEFAULT true
);
```

---

## 2. Contracts, brands, products

```sql
-- A principal may hold several concurrent contracts across territories.
-- Renewals are new rows, never edits to the old row.
CREATE TABLE distribution_contracts (
  id                    uuid PRIMARY KEY,
  principal_id          uuid NOT NULL REFERENCES business_partners,
  contract_number       text,
  territory_id          uuid REFERENCES territories,
  exclusive             boolean NOT NULL DEFAULT false,
  starts_on             date NOT NULL,
  ends_on               date,
  monthly_target        numeric(18,2),
  payment_terms_days    int,
  credit_limit          numeric(18,2)
);

CREATE TABLE brands (
  id                    uuid PRIMARY KEY,
  principal_id          uuid REFERENCES business_partners,   -- NULL = unbranded / generic
  code                  text NOT NULL UNIQUE,
  name                  text NOT NULL,
  logo_url              text,
  active                boolean NOT NULL DEFAULT true
);

CREATE TABLE categories (
  id                    uuid PRIMARY KEY,
  parent_id             uuid REFERENCES categories,
  name                  text NOT NULL
);

CREATE TABLE products (
  id                        uuid PRIMARY KEY,
  brand_id                  uuid REFERENCES brands,
  category_id               uuid REFERENCES categories,
  sku                       text NOT NULL UNIQUE,
  name                      text NOT NULL,
  base_uom                  text NOT NULL,   -- smallest unit, e.g. 'PCS'. All stock stored here.

  -- batch and expiry
  track_batch               boolean NOT NULL DEFAULT false,
  track_expiry              boolean NOT NULL DEFAULT false,
  issue_method              text NOT NULL DEFAULT 'fefo',  -- 'fefo' | 'fifo' | 'any'
  shelf_life_days           int,             -- expiry auto-computed from production date
  min_life_on_receipt_pct   numeric(5,2),    -- reject inbound below this % of shelf life
  min_life_on_ship_days     int,             -- block picking below this many days remaining

  -- physical
  weight_grams              numeric(12,2),
  volume_cm3                numeric(12,2),

  vat_percent               numeric(5,2) DEFAULT 11,
  reorder_point             numeric(18,4),
  active                    boolean NOT NULL DEFAULT true
);

-- Unit conversion: carton -> pack -> piece
CREATE TABLE product_uoms (
  id                    uuid PRIMARY KEY,
  product_id            uuid NOT NULL REFERENCES products,
  uom                   text NOT NULL,        -- 'CARTON'
  qty_in_base           numeric(18,4) NOT NULL, -- 1 CARTON = 144 PCS
  barcode               text,
  default_for_purchase  boolean NOT NULL DEFAULT false,
  default_for_sale      boolean NOT NULL DEFAULT false,
  UNIQUE (product_id, uom)
);
```

---

## 3. Pricing, discounts, principal programmes

```sql
CREATE TABLE price_lists (
  id                    uuid PRIMARY KEY,
  name                  text NOT NULL,
  starts_on             date,
  ends_on               date,
  active                boolean NOT NULL DEFAULT true
);

CREATE TABLE price_list_items (
  id                    uuid PRIMARY KEY,
  price_list_id         uuid NOT NULL REFERENCES price_lists,
  product_id            uuid NOT NULL REFERENCES products,
  uom                   text NOT NULL,
  min_qty               numeric(18,4) NOT NULL DEFAULT 0,   -- volume pricing bands
  unit_price            numeric(18,2) NOT NULL,
  UNIQUE (price_list_id, product_id, uom, min_qty)
);

-- Tiered discounts compound sequentially: 5% then 3% then 2%, never summed to 10%.
CREATE TABLE discount_rules (
  id                    uuid PRIMARY KEY,
  name                  text NOT NULL,
  scope                 text NOT NULL,   -- 'product' | 'brand' | 'category' | 'all'
  scope_id              uuid,
  target                text NOT NULL,   -- 'customer' | 'tier' | 'territory' | 'all'
  target_id             uuid,
  min_qty               numeric(18,4),
  min_amount            numeric(18,2),
  percent               numeric(5,2),
  amount                numeric(18,2),
  sequence              int NOT NULL DEFAULT 1,
  starts_on             date,
  ends_on               date
);

-- Buy-get promotions: buy 10 cartons, get 1 free.
CREATE TABLE promotions (
  id                    uuid PRIMARY KEY,
  name                  text NOT NULL,
  buy_product_id        uuid REFERENCES products,
  buy_brand_id          uuid REFERENCES brands,
  buy_qty               numeric(18,4) NOT NULL,
  buy_uom               text NOT NULL,
  get_product_id        uuid NOT NULL REFERENCES products,
  get_qty               numeric(18,4) NOT NULL,
  get_uom               text NOT NULL,
  max_per_order         numeric(18,4),
  starts_on             date NOT NULL,
  ends_on               date NOT NULL
);

CREATE TABLE rebate_programs (
  id                    uuid PRIMARY KEY,
  principal_id          uuid NOT NULL REFERENCES business_partners,
  brand_id              uuid REFERENCES brands,   -- NULL = across all of the principal's brands
  name                  text NOT NULL,
  basis                 text NOT NULL,   -- 'sell_in' | 'sell_out'
  period_start          date NOT NULL,
  period_end            date NOT NULL,
  status                text NOT NULL DEFAULT 'open'  -- open | claimed | settled
);

CREATE TABLE rebate_tiers (
  id                    uuid PRIMARY KEY,
  program_id            uuid NOT NULL REFERENCES rebate_programs,
  min_amount            numeric(18,2),
  min_qty               numeric(18,4),
  rebate_percent        numeric(5,2),
  rebate_amount         numeric(18,2)
);
```

---

## 4. Warehouse and inventory

Two principles govern this section.

The stock ledger is the single source of truth. Every movement writes a row; nothing bypasses it. `stock_balances` is a cache that can be rebuilt from the ledger at any time.

Ownership is tracked separately from location. `owner_id` is NULL for goods the distributor owns. Non-null means the goods sit in the warehouse but belong to someone else, and must be excluded from inventory value on the balance sheet.

```sql
CREATE TABLE warehouses (
  id                    uuid PRIMARY KEY,
  branch_id             uuid REFERENCES branches,
  code                  text NOT NULL UNIQUE,
  name                  text NOT NULL,
  warehouse_type        text NOT NULL DEFAULT 'normal',
    -- 'normal' | 'quarantine' | 'damaged' | 'consignment_in' | 'consignment_out'
  partner_id            uuid REFERENCES business_partners,
    -- for consignment_in: the principal who owns the goods
    -- for consignment_out: the outlet holding our goods
  active                boolean NOT NULL DEFAULT true
);

CREATE TABLE warehouse_locations (
  id                    uuid PRIMARY KEY,
  warehouse_id          uuid NOT NULL REFERENCES warehouses,
  code                  text NOT NULL,   -- 'A-01-03'
  pick_sequence         int,             -- drives picking list sort order
  UNIQUE (warehouse_id, code)
);

CREATE TABLE stock_batches (
  id                    uuid PRIMARY KEY,
  product_id            uuid NOT NULL REFERENCES products,
  batch_number          text NOT NULL,
  production_date       date,
  expiry_date           date,
  UNIQUE (product_id, batch_number)
);
CREATE INDEX ON stock_batches (product_id, expiry_date);

-- Append-only. Never UPDATE, never DELETE. Reversals are new negative rows.
CREATE TABLE stock_ledger (
  id                    bigserial PRIMARY KEY,
  warehouse_id          uuid NOT NULL REFERENCES warehouses,
  location_id           uuid REFERENCES warehouse_locations,
  product_id            uuid NOT NULL REFERENCES products,
  batch_id              uuid REFERENCES stock_batches,
  owner_id              uuid REFERENCES business_partners,  -- NULL = owned by us
  qty_base              numeric(18,4) NOT NULL,   -- positive in, negative out
  unit_cost             numeric(18,4) NOT NULL,
  value                 numeric(18,2) NOT NULL,
  ref_type              text NOT NULL,
    -- 'goods_receipt' | 'delivery' | 'adjustment' | 'transfer_out' | 'transfer_in'
    -- | 'sales_return' | 'purchase_return' | 'consignment_settlement'
  ref_id                uuid NOT NULL,
  ref_line_id           uuid,
  transaction_date      date NOT NULL,
  created_at            timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ON stock_ledger (product_id, warehouse_id, transaction_date);
CREATE INDEX ON stock_ledger (ref_type, ref_id);
CREATE INDEX ON stock_ledger (owner_id) WHERE owner_id IS NOT NULL;

CREATE TABLE stock_balances (
  warehouse_id          uuid NOT NULL REFERENCES warehouses,
  product_id            uuid NOT NULL REFERENCES products,
  batch_id              uuid REFERENCES stock_batches,
  owner_id              uuid REFERENCES business_partners,
  qty_base              numeric(18,4) NOT NULL DEFAULT 0,
  value                 numeric(18,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (warehouse_id, product_id, batch_id, owner_id)
);

-- Moving average cost, held per product. Lock this row when recalculating.
CREATE TABLE product_costs (
  product_id            uuid PRIMARY KEY REFERENCES products,
  average_cost          numeric(18,4) NOT NULL DEFAULT 0,
  total_qty             numeric(18,4) NOT NULL DEFAULT 0,
  total_value           numeric(18,2) NOT NULL DEFAULT 0,
  updated_at            timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE stock_reservations (
  id                    uuid PRIMARY KEY,
  warehouse_id          uuid NOT NULL REFERENCES warehouses,
  product_id            uuid NOT NULL REFERENCES products,
  qty_base              numeric(18,4) NOT NULL,
  ref_type              text NOT NULL,   -- 'sales_order'
  ref_id                uuid NOT NULL,
  status                text NOT NULL DEFAULT 'active',  -- active | consumed | released
  expires_at            timestamptz NOT NULL
);
CREATE INDEX ON stock_reservations (product_id, warehouse_id) WHERE status = 'active';

CREATE TABLE stock_transfers (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  from_warehouse_id     uuid NOT NULL REFERENCES warehouses,
  to_warehouse_id       uuid NOT NULL REFERENCES warehouses,
  transfer_date         date NOT NULL,
  transfer_reason       text,   -- 'rebalance' | 'consignment_placement' | 'quarantine'
  status                text NOT NULL DEFAULT 'draft'  -- draft | shipped | received | cancelled
);

CREATE TABLE stock_transfer_lines (
  id                    uuid PRIMARY KEY,
  transfer_id           uuid NOT NULL REFERENCES stock_transfers,
  product_id            uuid NOT NULL REFERENCES products,
  batch_id              uuid REFERENCES stock_batches,
  qty_base              numeric(18,4) NOT NULL,
  qty_received          numeric(18,4) DEFAULT 0
);

CREATE TABLE stock_counts (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  warehouse_id          uuid NOT NULL REFERENCES warehouses,
  count_date            date NOT NULL,
  count_type            text NOT NULL DEFAULT 'full',  -- 'full' | 'cycle'
  scope_category_id     uuid REFERENCES categories,
  status                text NOT NULL DEFAULT 'draft'  -- draft | counting | reviewed | posted
);

CREATE TABLE stock_count_lines (
  id                    uuid PRIMARY KEY,
  count_id              uuid NOT NULL REFERENCES stock_counts,
  product_id            uuid NOT NULL REFERENCES products,
  batch_id              uuid REFERENCES stock_batches,
  system_qty            numeric(18,4) NOT NULL,   -- frozen when the count is created
  counted_qty           numeric(18,4),
  variance              numeric(18,4) GENERATED ALWAYS AS (counted_qty - system_qty) STORED,
  note                  text
);

CREATE TABLE stock_adjustments (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  warehouse_id          uuid NOT NULL REFERENCES warehouses,
  adjustment_date       date NOT NULL,
  reason                text NOT NULL,   -- 'damage' | 'loss' | 'expired' | 'count_variance'
  count_id              uuid REFERENCES stock_counts,
  status                text NOT NULL DEFAULT 'draft',
  approved_by           uuid REFERENCES users,
  approved_at           timestamptz
);

CREATE TABLE stock_adjustment_lines (
  id                    uuid PRIMARY KEY,
  adjustment_id         uuid NOT NULL REFERENCES stock_adjustments,
  product_id            uuid NOT NULL REFERENCES products,
  batch_id              uuid REFERENCES stock_batches,
  qty_base              numeric(18,4) NOT NULL   -- may be negative
);
```

---

## 5. Purchasing

```sql
CREATE TABLE purchase_orders (
  id                    uuid PRIMARY KEY,
  branch_id             uuid REFERENCES branches,
  document_number       text NOT NULL UNIQUE,
  supplier_id           uuid NOT NULL REFERENCES business_partners,
  principal_id          uuid REFERENCES business_partners,
  warehouse_id          uuid NOT NULL REFERENCES warehouses,
  order_date            date NOT NULL,
  expected_date         date,
  payment_terms_days    int,
  subtotal              numeric(18,2) NOT NULL DEFAULT 0,
  discount_amount       numeric(18,2) NOT NULL DEFAULT 0,
  vat_amount            numeric(18,2) NOT NULL DEFAULT 0,
  total                 numeric(18,2) NOT NULL DEFAULT 0,
  status                text NOT NULL DEFAULT 'draft',
    -- draft | pending_approval | approved | partially_received | closed | cancelled
  approved_by           uuid REFERENCES users,
  approved_at           timestamptz,
  note                  text
);

CREATE TABLE purchase_order_lines (
  id                    uuid PRIMARY KEY,
  purchase_order_id     uuid NOT NULL REFERENCES purchase_orders,
  product_id            uuid NOT NULL REFERENCES products,
  uom                   text NOT NULL,
  qty_in_base           numeric(18,4) NOT NULL,   -- conversion snapshot at time of order
  qty                   numeric(18,4) NOT NULL,
  qty_base              numeric(18,4) NOT NULL,
  qty_received          numeric(18,4) NOT NULL DEFAULT 0,
  unit_price            numeric(18,2) NOT NULL,
  discount_percent      numeric(5,2) DEFAULT 0,
  discount_amount       numeric(18,2) DEFAULT 0,
  line_total            numeric(18,2) NOT NULL
);

CREATE TABLE goods_receipts (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  purchase_order_id     uuid REFERENCES purchase_orders,   -- NULL allowed: receipt without PO
  supplier_id           uuid NOT NULL REFERENCES business_partners,
  warehouse_id          uuid NOT NULL REFERENCES warehouses,
  owner_id              uuid REFERENCES business_partners, -- non-null for inbound consignment
  receipt_date          date NOT NULL,
  supplier_do_number    text,
  status                text NOT NULL DEFAULT 'draft'      -- draft | posted | cancelled
);

CREATE TABLE goods_receipt_lines (
  id                    uuid PRIMARY KEY,
  goods_receipt_id      uuid NOT NULL REFERENCES goods_receipts,
  purchase_order_line_id uuid REFERENCES purchase_order_lines,
  product_id            uuid NOT NULL REFERENCES products,
  batch_id              uuid REFERENCES stock_batches,
  location_id           uuid REFERENCES warehouse_locations,
  uom                   text NOT NULL,
  qty_in_base           numeric(18,4) NOT NULL,
  qty                   numeric(18,4) NOT NULL,
  qty_base              numeric(18,4) NOT NULL,
  qty_rejected          numeric(18,4) DEFAULT 0,
  rejection_reason      text,        -- 'short_shelf_life' | 'damaged' | 'wrong_item'
  unit_cost             numeric(18,4) NOT NULL
);

-- Three-way match: PO vs receipt vs invoice, before posting is permitted.
CREATE TABLE supplier_invoices (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  supplier_ref          text,
  tax_invoice_number    text,
  supplier_id           uuid NOT NULL REFERENCES business_partners,
  invoice_date          date NOT NULL,
  due_date              date NOT NULL,
  subtotal              numeric(18,2) NOT NULL,
  vat_amount            numeric(18,2) NOT NULL,
  total                 numeric(18,2) NOT NULL,
  paid_amount           numeric(18,2) NOT NULL DEFAULT 0,
  status                text NOT NULL DEFAULT 'draft'   -- draft | posted | paid | cancelled
);

CREATE TABLE supplier_invoice_lines (
  id                    uuid PRIMARY KEY,
  supplier_invoice_id   uuid NOT NULL REFERENCES supplier_invoices,
  goods_receipt_line_id uuid REFERENCES goods_receipt_lines,
  product_id            uuid REFERENCES products,
  qty                   numeric(18,4),
  unit_price            numeric(18,2),
  line_total            numeric(18,2) NOT NULL
);

CREATE TABLE purchase_returns (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  supplier_id           uuid NOT NULL REFERENCES business_partners,
  warehouse_id          uuid NOT NULL REFERENCES warehouses,
  goods_receipt_id      uuid REFERENCES goods_receipts,
  return_date           date NOT NULL,
  reason                text,
  status                text NOT NULL DEFAULT 'draft'
);

CREATE TABLE purchase_return_lines (
  id                    uuid PRIMARY KEY,
  purchase_return_id    uuid NOT NULL REFERENCES purchase_returns,
  product_id            uuid NOT NULL REFERENCES products,
  batch_id              uuid REFERENCES stock_batches,
  qty_base              numeric(18,4) NOT NULL,
  unit_price            numeric(18,2) NOT NULL
);

-- Claims against principals for damaged or expired goods.
CREATE TABLE principal_claims (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  principal_id          uuid NOT NULL REFERENCES business_partners,
  claim_date            date NOT NULL,
  claim_type            text NOT NULL,   -- 'expired' | 'damaged' | 'recall'
  total_amount          numeric(18,2) NOT NULL DEFAULT 0,
  approved_amount       numeric(18,2),
  status                text NOT NULL DEFAULT 'draft'  -- draft | submitted | approved | rejected | settled
);

CREATE TABLE principal_claim_lines (
  id                    uuid PRIMARY KEY,
  claim_id              uuid NOT NULL REFERENCES principal_claims,
  product_id            uuid NOT NULL REFERENCES products,
  batch_id              uuid REFERENCES stock_batches,
  qty_base              numeric(18,4) NOT NULL,
  unit_price            numeric(18,2) NOT NULL
);
```

---

## 6. Sales

```sql
CREATE TABLE sales_orders (
  id                    uuid PRIMARY KEY,
  branch_id             uuid REFERENCES branches,
  document_number       text NOT NULL UNIQUE,
  customer_id           uuid NOT NULL REFERENCES business_partners,
  shipping_address_id   uuid REFERENCES partner_addresses,
  salesperson_id        uuid REFERENCES salespeople,
  warehouse_id          uuid NOT NULL REFERENCES warehouses,
  order_date            date NOT NULL,
  requested_date        date,
  price_list_id         uuid REFERENCES price_lists,
  subtotal              numeric(18,2) NOT NULL DEFAULT 0,
  discount_amount       numeric(18,2) NOT NULL DEFAULT 0,
  vat_amount            numeric(18,2) NOT NULL DEFAULT 0,
  total                 numeric(18,2) NOT NULL DEFAULT 0,
  status                text NOT NULL DEFAULT 'draft',
    -- draft | credit_hold | pending_approval | approved | partially_shipped | closed | cancelled
  hold_reason           text,          -- 'over_credit_limit' | 'overdue_invoices' | 'customer_blocked'
  released_by           uuid REFERENCES users,
  released_at           timestamptz,
  source                text DEFAULT 'office',  -- 'office' | 'mobile' | 'import'
  offline_created_at    timestamptz    -- when captured on the salesperson's device
);

CREATE TABLE sales_order_lines (
  id                    uuid PRIMARY KEY,
  sales_order_id        uuid NOT NULL REFERENCES sales_orders,
  product_id            uuid NOT NULL REFERENCES products,
  uom                   text NOT NULL,
  qty_in_base           numeric(18,4) NOT NULL,
  qty                   numeric(18,4) NOT NULL,
  qty_base              numeric(18,4) NOT NULL,
  qty_shipped           numeric(18,4) NOT NULL DEFAULT 0,
  unit_price            numeric(18,2) NOT NULL,
  discount1_percent     numeric(5,2) DEFAULT 0,   -- compounds sequentially
  discount2_percent     numeric(5,2) DEFAULT 0,
  discount3_percent     numeric(5,2) DEFAULT 0,
  discount_amount       numeric(18,2) DEFAULT 0,
  is_bonus              boolean NOT NULL DEFAULT false,  -- zero revenue, still carries cost
  promotion_id          uuid REFERENCES promotions,
  line_total            numeric(18,2) NOT NULL
);

CREATE TABLE deliveries (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  customer_id           uuid NOT NULL REFERENCES business_partners,
  shipping_address_id   uuid REFERENCES partner_addresses,
  warehouse_id          uuid NOT NULL REFERENCES warehouses,
  delivery_date         date NOT NULL,
  driver_id             uuid REFERENCES users,
  vehicle_plate         text,
  status                text NOT NULL DEFAULT 'draft'
    -- draft | picking | loaded | in_transit | delivered | failed | cancelled
);

-- One delivery may cover several orders for the same customer.
CREATE TABLE delivery_orders (
  delivery_id           uuid NOT NULL REFERENCES deliveries,
  sales_order_id        uuid NOT NULL REFERENCES sales_orders,
  PRIMARY KEY (delivery_id, sales_order_id)
);

CREATE TABLE delivery_lines (
  id                    uuid PRIMARY KEY,
  delivery_id           uuid NOT NULL REFERENCES deliveries,
  sales_order_line_id   uuid REFERENCES sales_order_lines,
  product_id            uuid NOT NULL REFERENCES products,
  batch_id              uuid REFERENCES stock_batches,   -- chosen by FEFO at picking
  location_id           uuid REFERENCES warehouse_locations,
  qty_base              numeric(18,4) NOT NULL,
  qty_accepted          numeric(18,4),   -- captured at proof of delivery, may be lower
  batch_override_reason text,
  unit_cost             numeric(18,4)
);

CREATE TABLE delivery_pods (
  id                    uuid PRIMARY KEY,
  delivery_id           uuid NOT NULL REFERENCES deliveries,
  received_at           timestamptz NOT NULL,
  recipient_name        text,
  photo_url             text,
  signature_url         text,
  latitude              numeric(10,7),
  longitude             numeric(10,7),
  outcome               text NOT NULL,   -- 'full' | 'partial' | 'refused'
  note                  text
);

CREATE TABLE sales_invoices (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  tax_invoice_number    text,
  customer_id           uuid NOT NULL REFERENCES business_partners,
  delivery_id           uuid REFERENCES deliveries,
  settlement_id         uuid,            -- set when raised from a consignment settlement
  invoice_date          date NOT NULL,
  due_date              date NOT NULL,
  subtotal              numeric(18,2) NOT NULL,
  discount_amount       numeric(18,2) NOT NULL DEFAULT 0,
  vat_amount            numeric(18,2) NOT NULL DEFAULT 0,
  total                 numeric(18,2) NOT NULL,
  paid_amount           numeric(18,2) NOT NULL DEFAULT 0,
  status                text NOT NULL DEFAULT 'draft'
    -- draft | posted | exchanged | partially_paid | paid | cancelled
);
CREATE INDEX ON sales_invoices (customer_id, status, due_date);

CREATE TABLE sales_invoice_lines (
  id                    uuid PRIMARY KEY,
  sales_invoice_id      uuid NOT NULL REFERENCES sales_invoices,
  delivery_line_id      uuid REFERENCES delivery_lines,
  product_id            uuid NOT NULL REFERENCES products,
  uom                   text NOT NULL,
  qty                   numeric(18,4) NOT NULL,
  unit_price            numeric(18,2) NOT NULL,
  discount_amount       numeric(18,2) DEFAULT 0,
  line_total            numeric(18,2) NOT NULL
);

CREATE TABLE sales_returns (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  customer_id           uuid NOT NULL REFERENCES business_partners,
  sales_invoice_id      uuid REFERENCES sales_invoices,
  warehouse_id          uuid NOT NULL REFERENCES warehouses,
  return_date           date NOT NULL,
  reason                text NOT NULL,   -- 'damaged' | 'expired' | 'wrong_item' | 'unsold'
  resellable            boolean NOT NULL DEFAULT true,  -- false routes to the damaged warehouse
  status                text NOT NULL DEFAULT 'draft'
);

CREATE TABLE sales_return_lines (
  id                    uuid PRIMARY KEY,
  sales_return_id       uuid NOT NULL REFERENCES sales_returns,
  product_id            uuid NOT NULL REFERENCES products,
  batch_id              uuid REFERENCES stock_batches,
  qty_base              numeric(18,4) NOT NULL,
  unit_price            numeric(18,2) NOT NULL
);
```

---

## 7. Consignment

Outbound consignment works as a warehouse transfer, not a sale. Goods move to a virtual warehouse representing the outlet, so they stay visible in inventory. Revenue, cost of goods, and receivable only appear when the settlement is raised.

Inbound consignment works through `owner_id` on the ledger. The goods sit in a real warehouse but belong to the principal, so they must be excluded from inventory valuation. The payable appears only at settlement.

```sql
CREATE TABLE consignment_settlements (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  direction             text NOT NULL,   -- 'inbound' (settle to principal) | 'outbound' (from outlet)
  partner_id            uuid NOT NULL REFERENCES business_partners,
  warehouse_id          uuid NOT NULL REFERENCES warehouses,
  period_start          date NOT NULL,
  period_end            date NOT NULL,
  total_amount          numeric(18,2) NOT NULL DEFAULT 0,
  sales_invoice_id      uuid REFERENCES sales_invoices,
  supplier_invoice_id   uuid REFERENCES supplier_invoices,
  status                text NOT NULL DEFAULT 'draft'  -- draft | confirmed | invoiced | cancelled
);

CREATE TABLE consignment_settlement_lines (
  id                    uuid PRIMARY KEY,
  settlement_id         uuid NOT NULL REFERENCES consignment_settlements,
  product_id            uuid NOT NULL REFERENCES products,
  batch_id              uuid REFERENCES stock_batches,
  qty_placed            numeric(18,4) NOT NULL,   -- opening quantity for the period
  qty_sold              numeric(18,4) NOT NULL,
  qty_returned          numeric(18,4) NOT NULL DEFAULT 0,
  qty_remaining         numeric(18,4) NOT NULL,   -- carried into the next period
  unit_price            numeric(18,2) NOT NULL,
  line_total            numeric(18,2) NOT NULL
);
```

---

## 8. Receivables, payables, payments

```sql
-- Invoice exchange: the batch of invoices handed to a customer on their scheduled day.
CREATE TABLE invoice_exchanges (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  customer_id           uuid NOT NULL REFERENCES business_partners,
  exchange_date         date NOT NULL,
  promised_payment_date date,
  total_amount          numeric(18,2) NOT NULL DEFAULT 0,
  received_by           text,
  status                text NOT NULL DEFAULT 'draft'  -- draft | handed_over | settled
);

CREATE TABLE invoice_exchange_lines (
  exchange_id           uuid NOT NULL REFERENCES invoice_exchanges,
  sales_invoice_id      uuid NOT NULL REFERENCES sales_invoices,
  PRIMARY KEY (exchange_id, sales_invoice_id)
);

CREATE TABLE payments (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  direction             text NOT NULL,   -- 'inbound' (from customer) | 'outbound' (to supplier)
  partner_id            uuid NOT NULL REFERENCES business_partners,
  payment_date          date NOT NULL,
  method                text NOT NULL,   -- 'cash' | 'transfer' | 'cheque'
  amount                numeric(18,2) NOT NULL,
  cash_account_id       uuid,
  collected_by          uuid REFERENCES users,
  status                text NOT NULL DEFAULT 'draft'  -- draft | posted | cancelled
);

-- One payment may close many invoices; one invoice may be paid in instalments.
CREATE TABLE payment_allocations (
  id                    uuid PRIMARY KEY,
  payment_id            uuid NOT NULL REFERENCES payments,
  sales_invoice_id      uuid REFERENCES sales_invoices,
  supplier_invoice_id   uuid REFERENCES supplier_invoices,
  amount                numeric(18,2) NOT NULL
);

-- Postdated cheques. Not cash until cleared.
CREATE TABLE postdated_cheques (
  id                    uuid PRIMARY KEY,
  payment_id            uuid REFERENCES payments,
  partner_id            uuid NOT NULL REFERENCES business_partners,
  cheque_number         text NOT NULL,
  bank_name             text,
  received_on           date NOT NULL,
  due_on                date NOT NULL,
  amount                numeric(18,2) NOT NULL,
  status                text NOT NULL DEFAULT 'held',  -- held | deposited | cleared | bounced
  resolved_on           date,
  bounce_reason         text
);
CREATE INDEX ON postdated_cheques (status, due_on);
```

---

## 9. Accounting

```sql
CREATE TABLE chart_of_accounts (
  id                    uuid PRIMARY KEY,
  code                  text NOT NULL UNIQUE,
  name                  text NOT NULL,
  account_type          text NOT NULL,   -- 'asset' | 'liability' | 'equity' | 'revenue' | 'expense'
  parent_id             uuid REFERENCES chart_of_accounts,
  is_cash_account       boolean NOT NULL DEFAULT false,
  active                boolean NOT NULL DEFAULT true
);

-- Which accounts each document type debits and credits. Configurable per client.
CREATE TABLE journal_mappings (
  id                    uuid PRIMARY KEY,
  document_type         text NOT NULL,   -- 'goods_receipt' | 'delivery' | 'sales_invoice' | 'payment'
  posting_key           text NOT NULL,   -- 'inventory' | 'cogs' | 'revenue' | 'receivable' | 'vat_out'
  account_id            uuid NOT NULL REFERENCES chart_of_accounts,
  UNIQUE (document_type, posting_key)
);

CREATE TABLE journal_entries (
  id                    uuid PRIMARY KEY,
  document_number       text NOT NULL UNIQUE,
  entry_date            date NOT NULL,
  description           text,
  ref_type              text,            -- source document type, 'manual' for adjustments
  ref_id                uuid,
  status                text NOT NULL DEFAULT 'draft'  -- draft | posted | reversed
);

CREATE TABLE journal_lines (
  id                    uuid PRIMARY KEY,
  journal_entry_id      uuid NOT NULL REFERENCES journal_entries,
  account_id            uuid NOT NULL REFERENCES chart_of_accounts,
  partner_id            uuid REFERENCES business_partners,
  branch_id             uuid REFERENCES branches,
  debit                 numeric(18,2) NOT NULL DEFAULT 0,
  credit                numeric(18,2) NOT NULL DEFAULT 0,
  description           text,
  CHECK (debit = 0 OR credit = 0)
);
CREATE INDEX ON journal_lines (account_id, journal_entry_id);

CREATE TABLE accounting_periods (
  id                    uuid PRIMARY KEY,
  year                  int NOT NULL,
  month                 int NOT NULL,
  status                text NOT NULL DEFAULT 'open',  -- 'open' | 'closed'
  closed_by             uuid REFERENCES users,
  closed_at             timestamptz,
  UNIQUE (year, month)
);
```

---

## 10. Deferred constraints

Forward references, added after all tables exist.

```sql
ALTER TABLE customer_tiers    ADD FOREIGN KEY (price_list_id)  REFERENCES price_lists;
ALTER TABLE business_partners ADD FOREIGN KEY (tier_id)        REFERENCES customer_tiers;
ALTER TABLE business_partners ADD FOREIGN KEY (salesperson_id) REFERENCES salespeople;
ALTER TABLE business_partners ADD FOREIGN KEY (territory_id)   REFERENCES territories;
ALTER TABLE payments          ADD FOREIGN KEY (cash_account_id) REFERENCES chart_of_accounts;
ALTER TABLE sales_invoices    ADD FOREIGN KEY (settlement_id)  REFERENCES consignment_settlements;
```

---

## 11. Implementation notes

**Snapshot, never reference.** Prices, unit conversions, and discount rates are copied onto transaction lines at the moment the document is created. Storing only `product_id` and reading the price from the master means last year's invoices silently change value whenever someone edits the master.

**The base unit is the only storage unit.** The stock ledger knows nothing but `qty_base`. Conversion happens in the application layer at input and display.

**Exactly one code path writes stock.** Every document that moves goods calls the same function: write the ledger, update balances, recompute average cost, post the journal. Per-module stock logic guarantees that one module will eventually forget a step.

**Compute cost inside the same database transaction** as the ledger write, with `SELECT ... FOR UPDATE` on `product_costs`. Moving average calculated without a row lock will be wrong the first time two receipts post concurrently.

**Ownership filters every inventory query.** With consignment in play, any query that sums stock value for the balance sheet must filter `owner_id IS NULL`. Miss this and inventory is overstated by the value of goods the client doesn't own. Consider a database view for owned stock and make it the default in reporting code.

**Reservations expire.** Orders left unprocessed will otherwise lock stock indefinitely. Set `expires_at` on creation and run a cleanup job.

**Batch selection happens at picking, not ordering.** That's why `batch_id` lives on `delivery_lines` rather than `sales_order_lines`. Orders reserve quantity only, leaving the warehouse free to pick by nearest expiry.

**Shelf-life rules are hard validations.** `min_life_on_receipt_pct` blocks goods receipt; `min_life_on_ship_days` removes batches from the picking pool. Both must be enforced server-side, not just in the interface.

**Indexes that must exist from day one:** `stock_ledger(product_id, warehouse_id, transaction_date)`, `sales_invoices(customer_id, status, due_date)` for aging, `stock_batches(product_id, expiry_date)` for FEFO, and every `ref_type + ref_id` pair.

**Not yet modelled.** Salesperson visit routes, commission calculation, third-party freight costs, e-Faktur integration, document attachments, and modern trade deductions. All are additive — new tables, no changes to existing ones.
