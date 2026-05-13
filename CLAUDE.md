# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A small Laravel package that wraps the SenangPay (Malaysian payment gateway) HTTP API. It is consumed by host Laravel apps via Composer; there is no standalone runtime.

- Package name: `jiannius/senangpay`
- PSR-4 root: `Jiannius\Senangpay\` → `src/`
- Auto-discovery registers `SenangpayServiceProvider` (see `composer.json` `extra.laravel.providers`)
- Dev: `orchestra/testbench` ^8 is declared but there is currently no test suite, phpunit config, or lint config in the repo. The only authoritative install/check command is `composer install`. Use `php -l src/Senangpay.php` for ad-hoc syntax checks.

## Upstream documentation

When in doubt, the SenangPay docs are the source of truth — quote verbatim before changing hash/endpoint code:

- Index: https://guide.senangpay.com/developer-tools
- Hash type & credentials: https://guide.senangpay.com/merchant-id-and-secret-key
- Return URL: https://guide.senangpay.com/return-url
- Callback URL: https://guide.senangpay.com/what-is-callback-url-and-how-to-set-it
- Query order status: https://guide.senangpay.com/query-order-status
- Query transaction status: https://guide.senangpay.com/query-transaction-status
- Recurring callback: https://guide.senangpay.com/enhancement-of-recurring-features

## Architecture

Two files do all the work; the layout is intentional and worth keeping in mind before changing it.

**`src/Senangpay.php`** — the SDK class. Settings (`merchant_id`, `secret_key`, `sandbox`) are read from fluent setters first, then fall back to the host app's `config('services.senangpay.*')`. The service provider binds it into the container as `senangpay` (resolve via `app('senangpay')`), so callers normally do not `new` it directly.

**`src/SenangpayServiceProvider.php`** — registers the container binding and `loadRoutesFrom('../routes/web.php')`.

**`routes/web.php`** — registers three routes under prefix `__senangpay` (`redirect`, `recurring`, `webhook`) and dispatches them to `\App\Http\Controllers\SenangpayController` **in the host app**. That controller is NOT part of this package; the consuming application is expected to provide it. Routes use `->withoutMiddleware('web')` so CSRF/session middleware does not apply to the webhook.

### Endpoint routing rule (easy to break)

`getEndpoint($uri)` switches base URL on the `sandbox` setting, then:
- URIs matching `payment/*` are passed through as-is (e.g. `payment/{merchant_id}` is the hosted checkout page at the site root)
- Every other URI is prefixed with `/apiv1/` (e.g. `query_order_status` → `/apiv1/query_order_status`)

Preserve this split when adding new endpoints — the hosted checkout sits at the site root, all JSON APIs sit under `/apiv1/`.

### Hash contracts (do not modify casually)

The package only supports the SHA256 hash type. The merchant must select **SHA256** (not MD5) in their SenangPay dashboard under *Menu > Settings > Profile → SHOPPING CART INTEGRATION LINK*. If MD5 is selected, every hash will mismatch — there is no auto-detect.

Each SenangPay call uses an HMAC-SHA256 signature over a specific, ordered concatenation of fields, all keyed by `secret_key`. Changing field order or set will silently break integrations:

| Call | Concatenation (in order) | Source |
|------|---|---|
| `queryOrderStatus`, `test` | `merchant_id . secret_key . order_id` | docs `/query-order-status` |
| `checkout` outbound | `secret_key . detail . amount . order_id` | shopping-cart redirect flow |
| `validatePayload` (return URL + webhook) | `secret_key . status_id . order_id . transaction_id . msg` | docs `/return-url` (field-list variant) |
| `validateRecurringPayload` (recurring callback) | `secret_key . recurring_id . type . customer_email` | docs `/enhancement-of-recurring-features` |

`checkout` normalizes `amount` to a 2-decimal string with no thousands separator (`"2.00"`) before hashing — keep that normalization in sync with the hash input. This is the redirect/shopping-cart format; SenangPay's MOTO/Direct APIs (not used here) use integer cents (`200` for RM 2.00) — do not conflate the two if MOTO is ever added.

### Two payload validators, not one

`validatePayload()` and `validateRecurringPayload()` are NOT interchangeable — they hash different field sets. Route the controller actions accordingly:

- `redirect` action (return URL) → `validatePayload()`
- `webhook` action (server-to-server callback) → `validatePayload()` (same field set as Return URL per docs)
- `recurring` action → `validateRecurringPayload()` (recurring callbacks send `action`, `recurring_id`, `type`, `customer_email`, `new_payment_timestamp`, `hash` — none of the regular payment fields)

### Status normalization

`getStatus()` collapses both numeric (`0`/`1`/`2`) and string (`failed`/`paid`) status values from either `status_id` or `payment_info.status` into `failed` / `success` / `pending` / `null`. Both forms are real — `[TXN_STATUS]` from the return URL gives `0`/`1`; the `query_order_status` JSON response uses string forms. Reuse this method rather than re-inventing per-callsite checks. It does not understand recurring `action` values (`new_schedule`, `remove_schedule`, `terminate`) — read those directly off the payload.

## Host-app integration expectations

When debugging an issue reported by a consumer, remember the package assumes the host app provides:

- `config/services.php` entries under `senangpay` (`merchant_id`, `secret_key`, `sandbox` as a bool)
- `App\Http\Controllers\SenangpayController` with `redirect`, `recurring`, and `webhook` methods
- SenangPay dashboard hash type set to **SHA256**
- **`webhook` action must respond with the literal string `OK`** (no HTML tags, no JSON, no redirect). If SenangPay doesn't see `OK` in the response body, it treats the callback as failed and emails the merchant.
- **`webhook` action must be idempotent on `(order_id, status_id)`.** SenangPay calls the webhook on a schedule, not once: first call ~5 min after the transaction starts if still pending, real-time on any status change, and a final call ~1 hour after the transaction starts. The same successful payment will hit your webhook multiple times.

Issues that look like "routes 404" or "controller not found" are almost always missing host-app wiring, not a bug in this package.
