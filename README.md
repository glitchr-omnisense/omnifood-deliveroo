# omnifood/deliveroo

Deliveroo for [omniscient/omnifood](https://github.com/glitchr-studio/omnifood): the site's orders read
and listed, accepted or rejected (or their sync status, for a site with a Deliveroo tablet), the
kitchen's prep stages, the menu uploaded and its items made unavailable, the site opened, closed and
its hours, the order, rider and menu webhooks checked - through the Deliveroo Partner Platform
(Order, Menu and Site APIs).

> **Not verified against the live API (non vérifié en réel).** Deliveroo gives production keys only
> to approved partners: this package is written from Deliveroo's public documentation
> (https://api-docs.deliveroo.com, read on 2026-10-04) and tested against recorded answers. Its
> sandbox (`sandbox: true`) is the first step.

```php
use Omnifood\Deliveroo\DeliverooPlatformFactory;
use Symfony\Component\HttpClient\HttpClient;

$deliveroo = (new DeliverooPlatformFactory(HttpClient::create()))->create([
    'client_id' => getenv('DELIVEROO_CLIENT_ID') ?: null,
    'client_secret' => getenv('DELIVEROO_CLIENT_SECRET') ?: null,
    'brand_id' => getenv('DELIVEROO_BRAND_ID') ?: null,
    'site_id' => getenv('DELIVEROO_SITE_ID') ?: null,
    'menu_id' => getenv('DELIVEROO_MENU_ID') ?: null,
    'webhook_secret' => getenv('DELIVEROO_WEBHOOK_SECRET') ?: null,
    'sandbox' => true,
]);
```

Plain PHP, no framework needed: the factory takes any `HttpClientInterface` - the application's, a
`MockHttpClient` in a test - and makes its own when given none. In a Symfony application, the same
options under `omnifood.platforms` ([the bundle](https://github.com/glitchr-studio/omnifood/blob/1.x/docs/symfony.md)):

```yaml
omnifood:
    platforms:
        deliveroo:
            factory: deliveroo
            options:
                client_id: '%env(default::DELIVEROO_CLIENT_ID)%'
                client_secret: '%env(default::DELIVEROO_CLIENT_SECRET)%'
                brand_id: '%env(default::DELIVEROO_BRAND_ID)%'
                site_id: '%env(default::DELIVEROO_SITE_ID)%'          # the restaurant's location id
                menu_id: '%env(default::DELIVEROO_MENU_ID)%'
                webhook_secret: '%env(default::DELIVEROO_WEBHOOK_SECRET)%'
                sandbox: false
                tablet: false            # a Deliveroo tablet on site: accept()/deny() send the sync status
                language: fr             # the menu's texts
                mealtime_image: ~        # Deliveroo requires a picture for the menu (else the first item's)
```

## What it does

| Omnifood | Deliveroo |
|---|---|
| `order($id)` | `GET /order/v2/orders/{id}` |
| `orders($since)` | `GET /order/v2/brand/{brand}/restaurant/{site}/orders` (cursor, 30 days) |
| `accept($id)` | `PATCH /order/v1/orders/{id}` `accepted` (tablet: `POST .../sync_status` `succeeded`) |
| `deny($id, $reason, $note)` | `PATCH /order/v1/orders/{id}` `rejected` + `reject_reason` (tablet: sync status `failed`) |
| `ready($id)` | `POST /order/v1/orders/{id}/prep_stage` `ready_for_collection` |
| `prepStage($id, $stage, $delay)` | `in_kitchen`, `ready_for_collection_soon`, `collected` |
| `pushMenu($menu)` | `PUT /menu/v1/brands/{brand}/menus/{menu_id}` (mealtime, categories, items, modifiers) |
| `setAvailability($ref, ...)` | `POST /menu/v1/brands/{brand}/menus/{menu_id}/item_unavailabilities/{site}` |
| `status()`, `pause()`, `resume()` | `GET`/`PUT /site/v1/brands/{brand}/sites/{site}/status` |
| `setHours($hours)` | `POST /site/v1/brands/{brand}/sites/{site}/opening_hours` |
| `notify($body, $headers)` | `X-Deliveroo-Hmac-Sha256`: hex HMAC-SHA256 of guid + " " + body, the webhook secret |
| `token()`, `refresh()` | `POST https://auth.developers.deliveroo.com/oauth2/token`, client credentials (5 minutes) |

- An order not accepted within **10 minutes** (7 in some markets) is rejected by Deliveroo.
- `ready_for_collection` must be sent when someone presses "ready", never on a timer.
- The menu: options are items of the menu; every item needs its VAT rate (`tax_rate`, from
  Deliveroo's list per country) and a name of 2 to 120 characters, a category 3; the menu a picture.
  Allergens use Deliveroo's list: `GLUTEN` and `NUTS` declare the whole family unless the item's
  labels name its members (`gluten_wheat`, `nuts_almond`...); `no_allergens` is said by a label too.
- Out of stock: with an end, `unavailable` (Deliveroo resets it at the next opening - it takes no
  date); with none, `hidden`.
- `pause()` closes the site; Deliveroo takes no end: `resume()` opens it.

## Left in NotSupportedException

- `cancel()`: Deliveroo publishes no call for the restaurant to cancel an order; its customer service
  does, and the order webhook says so (https://api-docs.deliveroo.com/docs/cancelled-orders).

Not sent: `accept()`'s ready time (Deliveroo's `prepare_for` stands), the pause's end and reason,
`Hours::$exceptions` (Deliveroo's days off are not mapped), the legacy POS webhooks.

## What it takes

- An account and an **application** on the Developer Portal (developers.deliveroo.com): sandbox
  keys at once; **production keys** once every test scenario passes **and** the contract is approved.
- The **brand id** (from Deliveroo's integration team, or `GET /site/v1/brands`), the **site id**
  (the restaurant's location id, set with Deliveroo), a **menu id**.
- The **webhook URLs** (order and rider events) configured, then the **webhook secret** the account
  manager sends. A tablet-less site is switched by the account manager.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Deliveroo (Roofoods Ltd). It reaches their service on behalf of whoever runs it, and it
does not redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT
licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Deliveroo's terms ([API
Deliveroo Developers Terms of Use](https://developers.deliveroo.com/terms)), for obtaining whatever
they require (an account, a key, a licence or subscription, an attribution or a citation, a rate
limit to respect), and for any use made of what it returns. The authors publish it free of charge,
make no commercial use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
