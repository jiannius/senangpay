# Jiannius SenangPay

A Laravel SDK wrapper for [SenangPay](https://senangpay.my), the Malaysian payment gateway. It signs the outbound hash, validates inbound callbacks (regular and recurring), and wires the redirect / callback / webhook routes so you only have to write the controller actions.

## Requirements

- PHP 8.3+
- Laravel 13

## Installation

```bash
composer require jiannius/senangpay
```

The service provider is auto-discovered. Nothing to publish.

## Configuration

Add a `senangpay` entry to `config/services.php`:

```php
'senangpay' => [
    'merchant_id' => env('SENANGPAY_MERCHANT_ID'),
    'secret_key'  => env('SENANGPAY_SECRET_KEY'),
    'sandbox'     => env('SENANGPAY_SANDBOX', false),
],
```

In the SenangPay dashboard (*Menu → Settings → Profile → Shopping Cart Integration Link*), set **Hash Type to SHA256**. MD5 is not supported by this package and will silently mismatch every signature.

## Routes & the host controller

The service provider registers three routes under the `/__senangpay` prefix and dispatches them to `App\Http\Controllers\SenangpayController` in your app. You must create that controller.

| Method | Path                       | Action      | Purpose                                            |
| ------ | -------------------------- | ----------- | -------------------------------------------------- |
| GET    | `/__senangpay/redirect`    | `redirect`  | Return URL — browser lands here after payment      |
| GET    | `/__senangpay/recurring`   | `recurring` | Recurring-payment callback (new/remove/terminate)  |
| POST   | `/__senangpay/webhook`     | `webhook`   | Server-to-server callback                          |

Routes are registered with `->withoutMiddleware('web')` so the webhook is not blocked by CSRF.

```php
namespace App\Http\Controllers;

class SenangpayController extends Controller
{
    public function redirect()
    {
        $sp = app('senangpay');

        if (! $sp->validatePayload()) {
            abort(403);
        }

        return match ($sp->getStatus()) {
            'success' => view('checkout.success'),
            'failed'  => view('checkout.failed'),
            default   => view('checkout.pending'),
        };
    }

    public function webhook()
    {
        $sp = app('senangpay');

        if (! $sp->validatePayload()) {
            abort(403);
        }

        // Update your order here. MUST be idempotent — SenangPay calls this
        // webhook multiple times for the same transaction (see "Host-app
        // contracts" below).

        return 'OK'; // Literal string "OK". No HTML, no JSON, no redirect.
    }

    public function recurring()
    {
        $sp = app('senangpay');

        if (! $sp->validateRecurringPayload()) {
            abort(403);
        }

        $action = request()->input('action');
        // 'new_schedule' | 'remove_schedule' | 'terminate'
    }
}
```

## Usage

### Redirect to checkout

`checkout()` returns a Laravel `RedirectResponse` to SenangPay's hosted payment page. Return it directly from your controller.

```php
return app('senangpay')->checkout([
    'order_id' => $order->id,
    'name'     => $order->customer_name,
    'email'    => $order->customer_email,
    'phone'    => $order->customer_phone,
    'detail'   => 'Order #' . $order->id,
    'amount'   => $order->total, // numeric, e.g. 49.90
]);
```

`amount` is normalised to a two-decimal string (`"49.90"`) before hashing — pass it as a plain number, not a pre-formatted string.

### Query an order's status (server-to-server)

```php
$status = app('senangpay')->queryOrderStatus($orderId);
```

Returns the first record from SenangPay's `query_order_status` response.

### Normalised payment status

When called inside the `redirect` or `webhook` action, `getStatus()` collapses SenangPay's mixed numeric/string status values into one of `success`, `failed`, `pending`, or `null`.

```php
$result = app('senangpay')->getStatus(); // 'success' | 'failed' | 'pending' | null
```

### Per-tenant credentials at runtime

Setters override the `config('services.senangpay.*')` defaults for the current request.

```php
$sp = app('senangpay')
    ->setMerchantId($tenant->senangpay_merchant_id)
    ->setSecretKey($tenant->senangpay_secret_key)
    ->setSandbox($tenant->senangpay_sandbox);
```

### Test the connection

```php
['success' => $ok, 'error' => $err] = app('senangpay')->test();
```

Hits `query_order_status` with a dummy order id — useful for a settings-page "Test credentials" button.

## Host-app contracts

These aren't enforced by the package. Get them wrong and integrations silently break.

1. **The `webhook` action must respond with the literal string `OK`.** No HTML tags, no JSON, no redirect. If SenangPay doesn't see `OK` in the response body, the callback is treated as failed and the merchant gets an email.
2. **The `webhook` action must be idempotent on `(order_id, status_id)`.** SenangPay retries on a schedule: first call ~5 minutes after the transaction starts (if pending), real-time on any status change, and a final call ~1 hour after the transaction starts. A successful payment will hit your webhook multiple times.
3. **The dashboard hash type must be SHA256.** This package does not support MD5.

## Reference

SenangPay developer documentation:

- [Index](https://guide.senangpay.com/developer-tools)
- [Hash type & credentials](https://guide.senangpay.com/merchant-id-and-secret-key)
- [Return URL parameters](https://guide.senangpay.com/return-url)
- [Callback URL](https://guide.senangpay.com/what-is-callback-url-and-how-to-set-it)
- [Query order status](https://guide.senangpay.com/query-order-status)
- [Query transaction status](https://guide.senangpay.com/query-transaction-status)
- [Recurring callback](https://guide.senangpay.com/enhancement-of-recurring-features)

## License

MIT — see [LICENSE.md](LICENSE.md).
