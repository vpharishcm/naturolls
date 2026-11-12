# Architecture

## Overview

One Laravel API and one React PWA, on one domain. Terminals share a single database; each device also holds its own offline copy of the catalog and of orders waiting to sync.

- **Controllers** validate through Form Requests and hold no business rules.
- **Services** (`app/Services`) hold all business logic and transactions.
- **Resources** shape responses. `bootstrap/app.php` turns every error into the standard JSON format.
- **Frontend**: `pages/` for screens, `components/` for shared UI, `hooks/useOrderDraft.ts` for the order engine, `lib/` for API, offline, sync and printing, `stores/app.ts` (Zustand) for the session. React Query handles server data.

## Key decisions

**Inventory.** All stock changes pass through `InventoryService`. Each locks the `inventory` row (`SELECT … FOR UPDATE`), applies the change and writes one `inventory_movements` row. Available is physical minus reserved. The lock is what stops two terminals taking the last unit; a test races six real processes for two units.

**Lock order and retries.** Work on an order locks its `orders` row first, which serialises everything on that order, then inventory rows. Item rows are deliberately not range-locked: doing so caused gap-lock deadlocks in the race test. Top-level transactions retry up to three times if the database reports a deadlock.

**Serving.** The brief asks for stock to be consumed when served, and also rules out serving workflows. So served quantity is one optional tap, with no statuses or screens. On a bill, anything still unserved is consumed. `inventory_method = on_add` consumes on adding, for items where a reservation has no meaning.

| Event | Physical | Reserved |
| --- | --- | --- |
| Item added | | + |
| Unserved item removed | | − |
| Served, or billed while unserved | − | − |
| Served item removed | No change unless explicitly returned with approval | |
| Order cancelled | Served stays deducted | Unserved released |

**Double taps and retries.** Three layers: the UI ignores repeat taps within 180 ms, one worker sends **target quantities** (not "+1") one at a time, and writes that create things carry an `Idempotency-Key`. Billing also has `orders.version` (a stale screen gets 409) and a unique `invoices.order_id`.

**GST.** Off by default (setting `tax.enabled`): bills carry no tax and receipts show no tax lines or GSTIN. Items keep their rates, so turning it on taxes new bills only. Finished invoices keep what they charged.

**Money.** Calculated in integer paise. With GST on, prices include it by default (setting `tax.prices_inclusive`), so the bill total equals the design's shelf prices. Discounts apply in a fixed order (offer, manual, points), are spread across lines, then GST is worked out per rate and split evenly into CGST and SGST.

**Editing a bill.** `BillEditService` updates the invoice in place and keeps its number. Staff have `billing.staff_edit_minutes` (default 30) from the billing time, and an edit does not extend it. The permission `invoice.edit_anytime` (admin) removes the limit; the check is on the server. Money that changes hands is a new `payments` row, negative when given back, so a bill's rows always add up to its total and the difference counts on the day of the edit. These are not `refund` rows, because the invoice total already falls and a refund would be subtracted twice in reports. The previous items and total go to `audit_logs`. Editing is refused once a bill has a refund. The bill's offer, rupees of manual discount, points and GST status carry over; an offer whose minimum is no longer met is dropped.

**Printing.** The invoice commits before printing starts, so a failure cannot undo it. `print_count` rises only on success, which drives duplicate marking.

**Offline.** New orders made without a connection go to IndexedDB with a UUID and a number containing the terminal's code. `POST /orders/sync` is idempotent on the UUID. The items have already been handed over, so they are always recorded: stock takes what is available, and any shortfall is stored on the line and blocks billing until resolved. Stock never goes negative. Offline prices are honoured and differences flagged.

**Multiple terminals.** Terminals poll stock every 4 seconds and open orders every 5. Accuracy comes from database locks, not from the polling, which only keeps the display fresh. Websockets would make this instant but add a server process, which did not seem worthwhile for one counter.

**Limitations**

- Billing requires a connection. Invoice numbers must be sequential and unique across terminals, which cannot be guaranteed offline.
- Edits to server orders made while offline are held in memory on that screen, and are lost if the app is closed before reconnecting. New offline orders are stored and survive restarts.
- No specific UPI or card provider is integrated. Staff record those payments by hand; the signed callback endpoint is ready for an adapter.

## Database

All tables are InnoDB and utf8mb4. Money is `DECIMAL(…,2)` and quantity `DECIMAL(12,3)`. Foreign keys are enforced. Soft-delete tables are marked †.

### Access and system

| Table | Purpose | Relationships and constraints |
| --- | --- | --- |
| `users` † | Staff logins, hashed password and approval PIN | belongs to `roles`; unique `username` |
| `roles` | admin, staff | has many `users` |
| `permissions` | Named actions | many to many `roles` via `permission_role` |
| `permission_role` | Pivot | primary key on both |
| `personal_access_tokens` | Sanctum sessions | polymorphic to `users` |
| `terminals` | Devices and permanent offline codes | unique `device_id`, `code` |
| `settings` | Key and JSON value | unique `key` |
| `sequences` | Order and invoice counters | locked per key |
| `idempotency_keys` | Stored responses for replay | unique (`user_id`, `key`); pruned after 7 days |
| `audit_logs` | Sensitive actions, before and after, approver, reason | to `users`, `terminals` |
| `staff_activities` | Staff activity feed | to `users`, `terminals` |
| `backup_logs` | Backup runs, size, checksum, errors | to `users` |

### Catalog and stock

| Table | Purpose | Relationships and constraints |
| --- | --- | --- |
| `customers` † | Optional customer records | unique `mobile` |
| `categories` † | Unlimited, with subcategories and order | self reference `parent_id` |
| `products` † | Price, cost, GST, SKU, tracking, minimum stock, art | to `categories`; unique `sku`, `code` |
| `product_variants` † | Sizes and options | to `products` |
| `inventory` | Opening, physical and reserved per sellable item | to `products`, `product_variants`; unique `stock_key` (`p12` or `p12-v3`) |
| `inventory_reservations` | Live reservation per order line | to `inventory`; unique `order_item_id` |
| `inventory_movements` | Append-only ledger. The model refuses updates and deletes. | to `inventory`, `users` |

### Sales

| Table | Purpose | Relationships and constraints |
| --- | --- | --- |
| `orders` † | Unbilled, billed or cancelled; never hard deleted | to `customers`, `users`, `terminals`; unique `order_number`, `client_uuid` |
| `order_items` † | Lines with name, price, cost and GST snapshots; served and shortfall | to `orders`, `products`, `product_variants` |
| `invoices` | Final bill with an immutable snapshot and tax breakup | unique `invoice_number`, `order_id`; to `customers`, `users`, `promotions` |
| `payments` | Payments and refunds as separate rows | to `invoices`, `users`; refunds may reference a parent; unique `gateway_txn_id` |
| `cash_closings` | Daily drawer count and variance | unique `business_date` |
| `printer_logs` | Every print attempt | to `invoices`, `users`, `terminals` |

### Production, loyalty and offers

| Table | Purpose | Relationships and constraints |
| --- | --- | --- |
| `production_batches` | One production session: date, time, staff | to `users` |
| `production_batch_items` | Flavour and quantity; reversal details | to batches, `products`, `product_variants` |
| `loyalty_accounts` | Balance and lifetime totals | unique `customer_id` |
| `loyalty_transactions` | Earn, redeem, reversal, adjust; running balance | to accounts, `invoices`, `users` |
| `promotions` † | Type, value, scope, minimum, dates, approval | |
| `promotion_products`, `promotion_categories` | What an offer applies to | pivots |

## Scheduled jobs

Defined in `routes/console.php`: backup at 23:30, stock reconciliation at 23:45 (repairs totals from the ledger and logs each repair), idempotency key pruning daily.

## Security

Passwords and PINs are hashed. API tokens are per device. Permissions are checked on routes and for sensitive actions in services. Login and PIN attempts are rate limited. Spreadsheet exports neutralise formula-like text. Payment callbacks need an HMAC signature.
