# API reference

Base path: `/api/v1`. All requests send `Accept: application/json`. All except login and the payment callback send `Authorization: Bearer <token>`.

## Conventions

Success:

```json
{ "success": true, "data": {}, "meta": { "current_page": 1, "last_page": 3, "per_page": 25, "total": 60 } }
```

`meta` appears on paginated lists, which accept `page` and `per_page` (up to 100).

Failure:

```json
{ "success": false, "message": "Only 2 left of Mango.", "code": "insufficient_stock", "details": {}, "errors": {} }
```

| Status | Meaning |
| --- | --- |
| 401 | Not signed in, or a wrong login or signature |
| 403 | No permission, or owner approval needed (`authorization_required`) |
| 404 | Not found |
| 409 | Conflict with current state: `insufficient_stock`, `order_closed`, `order_changed`, `already_billed`, `order_cancelled`, `stock_shortfall` |
| 422 | Validation (`validation_failed`) or a business rule |
| 429 | Too many attempts, including `pin_locked` |

**Idempotency.** Send `Idempotency-Key: <unique value>` on any write. Repeating the key replays the first response with the header `Idempotent-Replay: true`. A key reused with a different body returns 422; a key still in progress returns 409. Server errors (5xx) release the key.

**Device.** Send `X-Device-Id` so that actions are attributed to the terminal.

**Owner approval.** Where marked *PIN*, a user without the permission may send `manager_pin`. Five wrong PINs lock that user out of PIN approval for a minute.

## Authentication

| Method and path | Body / notes |
| --- | --- |
| `POST /auth/login` | `username`, `password`, `device_id?`, `device_name?`. Returns `token`, `user`, `terminal`. Limited to 10 per minute. |
| `GET /auth/me` | User, permissions and settings |
| `POST /auth/logout` | Ends this token |

## Dashboard

`GET /dashboard`: today's sales, orders, bills, unbilled count and value, stock alerts, payment summary and backup state.

## Catalog

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET /categories` | | |
| `POST /categories`, `PUT /categories/{id}` | catalog.manage | `name`, `parent_id?`, `display_order?`, `is_active?` |
| `POST /categories/reorder` | catalog.manage | `ids[]` in the new order |
| `DELETE /categories/{id}` | catalog.manage | Soft delete |
| `GET /products` | | `search`, `category_id`, `include_inactive` |
| `GET /products/quick-lists` | | `favourites`, `frequent`, `recent` product ids |
| `GET /stock/availability` | | Stock per `stock_key`; terminals poll this |
| `POST /products`, `PUT /products/{id}` | catalog.manage | `category_id`, `name`, `price`, `cost?`, `gst_rate?`, `sku?`, `code?`, `description?`, `unit?`, `art?`, `art_color?`, `track_inventory?`, `inventory_method?` (`on_serve` or `on_add`), `min_stock?`, `is_active?`, `is_favourite?`, `opening_stock?`, `variants[]?` |
| `POST /products/bulk-prices` | catalog.manage | `prices[]` of `{id, price}` |
| `POST /products/{id}/image` | catalog.manage | Multipart `image`, up to 4 MB |
| `POST /products/{id}/favourite` | | `is_favourite` |
| `DELETE /products/{id}` | catalog.manage | Soft delete; hides from billing |

## Orders and order items

| Method and path | Notes |
| --- | --- |
| `GET /orders` | `status`, `from`, `to`, `search` (number, name or mobile) |
| `POST /orders` | `customer_id?`, `customer_name?`, `customer_mobile?` |
| `GET /orders/{id}` | With items |
| `PATCH /orders/{id}` | Customer fields |
| `POST /orders/{id}/items` | `product_id`, `product_variant_id?`, `quantity?` (default 1). Reserves stock. |
| `PATCH /orders/{id}/items/{item}` | `quantity`, `return_to_stock?`, `manager_pin?` (*PIN* to return served stock) |
| `DELETE /orders/{id}/items/{item}` | Same options as above |
| `POST /orders/{id}/items/{item}/serve` | `quantity?` (default all). Consumes stock. |
| `POST /orders/{id}/items/{item}/resolve` | Retries reservation after a sync shortfall |
| `POST /orders/{id}/cancel` | `reason`, *PIN*, permission order.cancel |
| `POST /orders/sync` | Offline order: `client_uuid`, `order_number`, `created_at`, `customer_name?`, `customer_mobile?`, `items[]` of `{product_id, product_variant_id?, quantity, unit_price}`. Returns `order`, `duplicate`, `conflicts[]`. |

## Billing, invoices and printing

| Method and path | Notes |
| --- | --- |
| `POST /orders/{id}/bill/preview` | `promotion_ids?` (offers to use; omit for every automatic offer that helps), `promotion_id?` (older single-offer form), `no_promotion?`, `manual_discount?` (`{type, value}`), `redeem_points?`. Returns totals, tax, `promotions` (each applied offer and its saving), `available_promotions` and `version`. |
| `POST /orders/{id}/bill` | The above plus `expected_version`, `payments[]` of `{method, amount, tendered?, reference?}`, `discount_reason?`, *PIN* for discounts. Payments must equal the total. |
| `GET /invoices`, `GET /invoices/{id}` | `search`, `customer_id` |
| `GET /invoices/{id}/receipt` | Receipt data and `escpos_base64` |
| `POST /invoices/{id}/print` | Sends to the network printer. Returns `printed`, `error`, `duplicate`. |
| `POST /invoices/{id}/print-log` | Device prints: `driver`, `status`, `error?` |
| `POST /invoices/{id}/refunds` | `amount`, `method`, `reason`, `reference?`, `restock[]?`, *PIN*, permission refund.create |
| `POST /invoices/{id}/edit/preview` | `expected_edit_count`, `items[]` of `{id, quantity}` for lines on the bill or `{product_id, product_variant_id?, quantity}` for new ones. Lines left out are removed. Returns totals and `difference`, and saves nothing. |
| `POST /invoices/{id}/edit` | The above plus `reason`, `return_to_stock?` (default true), and `settlement_method` when the total changes. Refused with `edit_window_closed` when staff are past the time limit, `invoice_refunded` after a refund. |
| `GET /printer/status`, `GET /printer/logs` | |

## Payments

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET /payments` | | `date`, `method`, `unreconciled` |
| `GET /payments/summary` | | `date` |
| `POST /payments/reconcile` | payments.manage | `payment_ids[]` |
| `POST /payments/cash-closing` | payments.manage | `business_date`, `counted_balance`, `notes?` |
| `POST /payments/callback` | Signature | `gateway_txn_id`, `reference`, `amount`, `status` |

The callback sends `X-Signature`, the hex HMAC-SHA256 of the raw body using `PAYMENT_CALLBACK_SECRET`. Repeats return `duplicate: true` and change nothing.

## Inventory

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET /inventory` | | `search`, `filter` (`low`, `out`) |
| `GET /inventory/movements` | | `inventory_id`, `type` |
| `POST /inventory/{id}/correct` | stock.correct | `counted`, `reason` |
| `POST /inventory/{id}/wastage` | stock.correct | `quantity`, `reason` |
| `POST /inventory/{id}/opening` | stock.correct | `quantity` |
| `POST /inventory/reconcile` | stock.correct | `repair?` |

## Production

| Method and path | Notes |
| --- | --- |
| `GET /production` | `from`, `to` |
| `POST /production` | `production_date`, `production_time?`, `notes?`, `items[]` of `{product_id, product_variant_id?, quantity}`. Permission production.record. |
| `POST /production/items/{id}/reverse` | `reason`, *PIN* |
| `POST /production/items/{id}/edit` | `quantity`, `reason`, *PIN* |

## Customers and loyalty

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET /customers`, `GET /customers/{id}` | | `search`. Detail includes loyalty and recent invoices. |
| `POST /customers`, `PUT /customers/{id}`, `DELETE /customers/{id}` | customers.manage | `name`, `mobile?`, `birthday?`, `notes?`, `join_loyalty?` |
| `POST /customers/{id}/loyalty` | customers.manage | Registers for points |
| `POST /loyalty/{account}/adjust` | loyalty.adjust | `points`, `note` |

Earning and redemption happen during `POST /orders/{id}/bill`.

## Reports

Permission reports.view. `GET /reports` lists them. `GET /reports/{type}` takes `from`, `to`, and returns `title`, `columns`, `rows`, `summary`. `GET /reports/{type}/export/{xlsx|csv|pdf}` downloads a file.

Types: `sales-period` (`group`: day, week or month), `sales-product`, `sales-category`, `sales-hours`, `stock-current`, `stock-movements`, `stock-consumption`, `stock-wastage`, `stock-low`, `financial-summary`, `financial-payments`, `financial-refunds`, `financial-profit`, `production-daily`, `production-flavour`, `production-vs-sales`, `staff-summary`, `staff-activity`, `staff-modifications`, `staff-cancellations`, `customer-spending`, `customer-repeat`, `customer-history` (`customer_id`), `loyalty-summary`, `promotion-summary`.

## Promotions, staff, settings and backups

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET /promotions` | | |
| `POST /promotions`, `PUT /promotions/{id}`, `DELETE /promotions/{id}` | promotions.manage | `name`, `discount_type` (`percent`, `fixed`, `buy_get`, `bundle`), `discount_value` (the set price for `bundle`, unused for `buy_get`), `buy_qty?`, `get_qty?`, `get_scope?` (`same`, `product`, `category`), `get_product_ids[]?`, `get_category_ids[]?`, `scope` (`order`, `product`, `category`), `min_purchase?`, `starts_at?`, `ends_at?`, `is_active?`, `requires_authorization?`, `product_ids[]?`, `category_ids[]?` |
| `GET /staff`, `POST /staff`, `PUT /staff/{id}` | staff.manage | `name`, `username`, `password`, `pin?`, `role_id`, `is_active?` |
| `GET /audit-logs` | staff.manage | `action` prefix |
| `GET /settings` | | |
| `PUT /settings` | settings.manage | `values`: object of setting keys |
| `GET /backups`, `POST /backups`, `GET /backups/{id}/download` | backups.manage | |
