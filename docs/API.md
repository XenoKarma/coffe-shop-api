# NARA Coffee Shop POS — API Documentation

Base URL: `http://<host>/api` (no version prefix, base path is `/api`).

| Item | Value |
| --- | --- |
| Authentication | `Authorization: Bearer <token>` (Laravel Sanctum personal access token) |
| Content type | `application/json`, except image upload (`multipart/form-data`) |
| Roles | `admin`, `cashier` |
| Currency | IDR, numeric (no decimal required) |
| Timezone | `Asia/Jakarta` (`config/app.php`) |
| Pagination | 15 per page, `?page=<n>` |

## Conventions

- Successful responses wrap payloads in `data`, except authentication endpoints which use the documented `{message, token, user}` shape.
- Create returns `201`, delete returns `204 No Content`, conflicts return `409`.
- List endpoints that paginate return `{data, links, meta}` (Laravel paginator).
- Timestamps are ISO-8601.
- All write inputs are validated; unknown/extra fields are ignored.

## Status codes

| Code | Meaning | Shape |
| --- | --- | --- |
| 200 | OK | `data` (resource) |
| 201 | Created | `data` (resource) |
| 204 | Deleted | empty body |
| 401 | Unauthenticated / invalid credentials / invalid token | `{message}` |
| 403 | Wrong role | `{"message": "Forbidden."}` |
| 404 | Unknown resource or path | `{message}` |
| 405 | Method not allowed | `{message}` |
| 409 | Business conflict | `{message}` |
| 422 | Validation error | `{message, errors: {field: [msg]}}` |
| 429 | Rate limited (login: 5/min per IP) | `{message}` |

---

## Authentication

### POST /api/login

Public. Rate limited to **5 attempts per minute**.

Request:

```json
{"email": "admin@nara.test", "password": "password"}
```

200:

```json
{
  "message": "Login Successful.",
  "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "user": {"id": 1, "name": "Admin", "email": "admin@nara.test", "role": "admin"}
}
```

Errors: `401 {"message": "Invalid Credentials."}` (same body for unknown email and wrong password), `422`, `429`.

### GET /api/me

Requires token. 200: `data` = `{id, name, email, role}` (never exposes `password` / `remember_token`).

### POST /api/logout

Requires token. Revokes the current token. 200: `{"message": "Logout successful."}`.

---

## Categories

Reads are public; writes require `admin`.

| Method | Path | Access | Success |
| --- | --- | --- | --- |
| GET | /api/categories | public | 200 `{data: [{id, name, description}]}` (sorted by name, not paginated) |
| GET | /api/categories/{id} | public | 200 `data` |
| POST | /api/categories | admin | 201 `data` |
| PUT/PATCH | /api/categories/{id} | admin | 200 `data` |
| DELETE | /api/categories/{id} | admin | 204 |

Body (POST/PUT): `{"name": "Tea", "description": "optional"}` — `name` required (max 255).

Delete conflict `409`: `{"message": "Cannot delete category with existing products."}`.

---

## Products

Reads are public; writes require `admin`.

| Method | Path | Access | Success |
| --- | --- | --- | --- |
| GET | /api/products | public | 200 paginated `{data, links, meta}` |
| GET | /api/products/{id} | public | 200 `data` (includes nested `category`) |
| POST | /api/products | admin | 201 `data` |
| PUT/PATCH | /api/products/{id} | admin | 200 `data` |
| DELETE | /api/products/{id} | admin | 204 |

`data` shape: `{id, category_id, name, sku, description, price, image, is_active, category}` where `image` is a full URL or `null`.

Query parameters for `GET /api/products`:

| Param | Example | Meaning |
| --- | --- | --- |
| `category_id` | `?category_id=3` | filter by category |
| `is_active` | `?is_active=false` | filter by active flag |
| `search` | `?search=latte` | case-insensitive match on name or SKU |
| `page` | `?page=2` | pagination |

Body (multipart/form-data recommended when sending `image`):

| Field | Rules |
| --- | --- |
| `category_id` | required, exists |
| `name` | required, max 255 |
| `sku` | required, max 50, unique (ignored for current product on update) |
| `description` | nullable |
| `price` | required, numeric, min 0 |
| `image` | optional, jpg/jpeg/png/webp, max 2 MB; replaced image deletes old file |
| `is_active` | optional boolean, default `true` on create |

Delete conflict `409`: `{"message": "Cannot delete a product that has been ordered. Set is_active to false instead."}`.

---

## Orders (POS checkout)

All order routes require `admin` or `cashier`.

Order `status` lifecycle: `pending` → `paid` (after payment) or `pending` → `cancelled` (cashier voids before payment). Only `pending` orders can be edited or cancelled; `paid` and `cancelled` are final.

### POST /api/orders — 201

Request:

```json
{"items": [{"product_id": 5, "quantity": 2}, {"product_id": 8, "quantity": 1}]}
```

Server computes prices/totals from the database (client prices are never trusted). The order is created with `status: "pending"` and `order_number` like `ORD-20261004-A1B2C`. Snapshot fields `product_name`, `price`, `subtotal` are stored per item.

Response `data`:

```json
{
  "id": 12,
  "order_number": "ORD-20261004-A1B2C",
  "status": "pending",
  "subtotal": 54000,
  "discount": 0,
  "tax": 0,
  "total": 54000,
  "cashier": {"id": 2, "name": "Kasir", "email": "kasir@nara.test", "role": "cashier"},
  "items": [{"id": 1, "product_id": 5, "product_name": "Cafe Latte", "price": 22000, "quantity": 2, "subtotal": 44000}],
  "payment": null,
  "created_at": "..."
}
```

Validation `422`: `items` required (min 1 entry), each `product_id` must exist, each `quantity` min 1, duplicate products rejected (`items` error), inactive product rejected (`items.N.product_id`: `Product [Name] is not available.`). On any failure no order/partial data is persisted (single transaction).

### GET /api/orders — 200 (paginated, newest first)

Query parameters: `status` (`pending`|`paid`|`cancelled`), `date=YYYY-MM-DD`, `cashier_id`, `page`.

### GET /api/orders/{id} — 200

Full order with `cashier`, `items`, `payment` (or `payment: null` when still pending or cancelled).

### PUT /api/orders/{id} — 200

Replaces the item list of a **pending** order (correction before payment). Same request shape and validation as `POST /api/orders`. Totals and per-item snapshots are recomputed from current database prices in one transaction (old items are deleted and rebuilt); `order_number`, `created_at`, and cashier are unchanged. No new order is created.

Errors: `409` if the order is not `pending` (`Order is not pending.`), `422` same rules as create (`items`, duplicates, inactive/unknown product). On failure the original items and totals remain intact (transaction rollback).

### PATCH /api/orders/{id}/cancel — 200

Voids a **pending** order (set `status` to `cancelled`). Response `data` is the full order.

Errors `409`:

- already cancelled → `{"message": "Order is already cancelled."}`
- paid → `{"message": "Paid orders cannot be cancelled."}`

Cancelled orders stay in the list (`?status=cancelled`) and are excluded from sales reports.

### PATCH /api/orders/{id}/pay — 200

Request: `{"paid_amount": 60000}` (`required`, `numeric`, `min:0`).

Creates the cash payment and flips `status` to `paid` in one transaction. `change = paid_amount - total`, computed server-side.

Response `data` includes:

```json
"payment": {"id": 3, "method": "cash", "paid_amount": 60000, "change": 6000, "paid_at": "..."}
```

Errors: `409` if the order is not `pending` (`Order is not pending.`), `422` if `paid_amount` is less than the total.

---

## Reports

### GET /api/reports/sales — admin only

Aggregates **only paid orders** (gross sales, not profit).

```json
{
  "data": {
    "today": {"sales": 216000, "orders": 4},
    "month": {"sales": 4320000, "orders": 80},
    "total": {"sales": 9864000, "orders": 190},
    "best_sellers": [
      {"product_id": 5, "product_name": "Cafe Latte", "quantity": 42, "sales": 924000}
    ]
  }
}
```

`best_sellers`: top 5 by quantity sold (paid orders), `product_name` is the historical snapshot from order items. `today`/`month` are based on `Asia/Jakarta` calendar time. `pending` and `cancelled` orders are never counted.

---

## Roles matrix

| Endpoint | guest | cashier | admin |
| --- | --- | --- | --- |
| login / me / logout | login public | ✓ (own token) | ✓ |
| GET categories, GET products | ✓ | ✓ | ✓ |
| Category write | 401 | 403 | ✓ |
| Product write | 401 | 403 | ✓ |
| Orders create/list/detail | 401 | ✓ | ✓ |
| Orders update/cancel/pay | 401 | ✓ | ✓ |
| GET /api/reports/sales | 401 | 403 | ✓ |

Every `403` body is exactly `{"message": "Forbidden."}`.

## Security notes

- Passwords hashed (bcrypt); login timing equalized against user enumeration.
- Mass assignment blocked by `validated()` + `#[Fillable]` whitelists.
- Tokens are hashed at rest and revocable via logout.
- Rate limit: login 5/min per IP.
- Production checklist: `APP_DEBUG=false`, restrict CORS origin, serve over HTTPS.
