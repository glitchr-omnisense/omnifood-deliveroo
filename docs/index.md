# omnifood/deliveroo

Deliveroo for [omnisense/omnifood](https://github.com/glitchr-omnisense/omnifood): a site's
orders read, listed, accepted, rejected and marked ready, its menu uploaded and its items made
unavailable, the site opened, closed and its opening hours, and the order, rider and menu webhooks
checked - through the Deliveroo Partner Platform (Order API, Menu API, Site API). The calls go to
`https://api.developers.deliveroo.com` with a token from
`https://auth.developers.deliveroo.com/oauth2/token`, or to
`https://api-sandbox.developers.deliveroo.com` and
`https://auth-sandbox.developers.deliveroo.com/oauth2/token` with `sandbox: true`.

| Operation | Deliveroo |
|---|---|
| `order($id)` | `GET /order/v2/orders/{id}` |
| `orders($since)` | `GET /order/v2/brand/{brand}/restaurant/{site}/orders` (cursor) |
| `accept($id)` | `PATCH /order/v1/orders/{id}` `accepted` (tablet: `POST .../sync_status` `succeeded`) |
| `deny($id, $reason, $note)` | `PATCH /order/v1/orders/{id}` `rejected` (tablet: sync status `failed`) |
| `ready($id)` | `POST /order/v1/orders/{id}/prep_stage` `ready_for_collection` |
| `prepStage($id, $stage, $delay)` | `POST /order/v1/orders/{id}/prep_stage` |
| `syncStatus($id, $succeeded, ...)` | `POST /order/v1/orders/{id}/sync_status` |
| `pushMenu($menu)` | `PUT /menu/v1/brands/{brand}/menus/{menu_id}` |
| `setAvailability($ref, ...)` | `POST /menu/v1/brands/{brand}/menus/{menu_id}/item_unavailabilities/{site}` |
| `status()` | `GET /site/v1/brands/{brand}/sites/{site}/status` |
| `pause()`, `resume()` | `PUT /site/v1/brands/{brand}/sites/{site}/status` |
| `setHours($hours)` | `POST /site/v1/brands/{brand}/sites/{site}/opening_hours` |
| `token()`, `refresh()` | `POST /oauth2/token`, client credentials, HTTP Basic |

## Installation

```sh
composer require omnifood/deliveroo
```

Requires PHP 8.2, `omnisense/omnifood` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a Symfony
application with `Omnifood\Bridge\Symfony\OmnifoodBundle`, the factory is registered as soon as
the package is installed.

## Credentials

- An account and an **application** on the Developer Portal (developers.deliveroo.com): sandbox
  keys at once; **production keys** once every test scenario passes **and** the contract is
  approved. The application's **client id and secret**.
- The **brand id** (from Deliveroo's integration team, or `GET /site/v1/brands`), the **site id**
  (the restaurant's location id, set with Deliveroo), a **menu id**.
- The **webhook URLs** (order and rider events) configured, then the **webhook secret** the
  account manager sends. A tablet-less site is switched by the account manager.

The webhook URLs are set once with Deliveroo's own calls, which this package does not make:
`PUT /order/v1/integrator/webhooks/order-events` and `.../rider-events` with
`{"webhook_url": "https://..."}`, then the sites' webhook type
(`PUT /order/v1/integrator/brands/{brand_id}/sites-config`).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | for every call | the application's client id |
| `client_secret` | string | - | for every call | its secret |
| `brand_id` | string | - | for `orders()`, the menu and the site | the brand's id |
| `site_id` | string | - | for `orders()`, the menu and the site | the restaurant's location id |
| `menu_id` | string | - | for `pushMenu()` and `setAvailability()` | the menu written to |
| `webhook_secret` | string | - | for `notify()` | the webhooks' signing key |
| `sandbox` | bool | `false` | no | the sandbox API and token endpoint |
| `tablet` | bool | `false` | no | the site has a Deliveroo tablet: `accept()` and `deny()` send the till's sync status |
| `language` | string | `fr` | no | the language key of the menu's texts |
| `mealtime_image` | string (URL) | - | no | the menu's picture (Deliveroo requires one); none: the first item's photo |
| `base_uri` | string | the live or sandbox API | no | another API address |
| `token_url` | string | the live or sandbox endpoint | no | another token endpoint |

No option is checked when the platform is built; a call that needs one and finds it empty throws
`InvalidConfigException` (`The "deliveroo" platform needs: brand_id.`). Deliveroo's token lives 5
minutes and has no refresh token: there is no `access_token` option.

## Capabilities

| Interface | Supported |
|---|---|
| `OrdersInterface` | `order()`, `orders()`, `accept()`, `deny()`, `ready()`; `cancel()` refused; and `prepStage()`, `syncStatus()` |
| `MenuInterface` | yes |
| `StoreInterface` | yes; `pause()` takes no end; `setHours()` sends the week only |
| `ReservationsInterface` | no |
| `NotifiableInterface` | yes |
| `Auth\RefreshableInterface` | yes |

`capabilities()`: order types delivery, pickup and dine-in; `acceptanceDelay` 600 (10 minutes;
7 in some markets); `readyAt` false; `modifierDepth` 2; `allergens`, `photos`, `vatRates` and
`modifiersAreItems` true; names at most 120 characters, descriptions 500; `pauseUntil` false.

## Behaviour

**Token.** Asked for on the first call that needs one: `POST` with HTTP Basic (the client id and
secret) and `grant_type=client_credentials`. Kept until 20 seconds before it expires, then asked
for again. Every call sends `Authorization: Bearer` and `Accept: application/json`.

**Orders.** `orders($since)` sends `start_date` (UTC, `Y-m-d\TH:i:s\Z`; the last 24 hours by
default) and follows `next` as the `cursor`; Deliveroo keeps 30 days. Newest first by `placedAt`.
The mapping:

- `reference` `id`, `displayId` `display_id`, `store` `location_id`, `placedAt` the `placed`
  entry of `status_log`, `pickupAt` `prepare_for`, `deliverAt` `delivery.deliver_by`;
- `type`: `fulfillment_type` `customer` is pickup, `table_service` dine-in, anything else
  delivery;
- `status`: `pending`, `placed` `NEW`; `accepted`, `confirmed` `ACCEPTED`, then `PICKED_UP` when
  the prep stages hold `collected`, `READY` when they hold `ready_for_collection`; `rejected`
  `DENIED`; `canceled` `CANCELLED`; anything else `UNKNOWN`;
- the lines: `name` (else `operational_name`), `quantity`, `unit_price`, `total_price`,
  `pos_item_id` as `posRef`, the modifiers nested;
- amounts are Deliveroo's `fractional` minor units: `total` `total_price`, `subtotal`
  `subtotal`, `discount` `offer_discount`, `fees` the sum of `bag_fee`, `surcharge` and the
  delivery's `delivery_fee`; `tax` is not read;
- the customer: first name, contact number and access code (from `customer`, else the delivery),
  the address (`line1`, `line2`, postcode and city) when Deliveroo gives one;
- the note joins `order_notes`, the delivery's note and `Table <n>` for a table number. The
  courier is not on the order (the rider webhook).

**Accept and reject.** Without a tablet, `PATCH` `{"status": "accepted"}` or `{"status":
"rejected", "reject_reason", "notes"}`, the reason `closing_early` (`CLOSED`), `busy`
(`TOO_BUSY`), `ingredient_unavailable` (`ITEM_UNAVAILABLE`) or `other`. `$readyAt` is not sent:
Deliveroo's `prepare_for` stands. With `tablet: true` the order is accepted on the tablet, and
`accept()` / `deny()` send the sync status: `succeeded`, or `failed` with the reason
`items_out_of_stock` (`ITEM_UNAVAILABLE`), `price_mismatched` (`PRICING`), `location_offline`
(`CLOSED`) or `other`, the note, and `occurred_at` (now, UTC). An order neither accepted nor
rejected within 10 minutes (7 in some markets) is rejected by Deliveroo.

**Ready.** `ready()` sends the prep stage `ready_for_collection` with `occurred_at`; Deliveroo asks
that it be sent when someone presses "ready", never on a timer. `prepStage($ref, $stage,
$delay)` sends any stage: `in_kitchen` (with a delay of 0, 2, 4, 6, 8 or 10 minutes),
`ready_for_collection_soon`, `ready_for_collection`, `collected`.

**Cancel.** Refused: Deliveroo publishes no call for the restaurant to cancel an order; its
customer service does, and the order webhook says so
(https://api-docs.deliveroo.com/docs/cancelled-orders).

**Menu.** Checked by the core's validator, plus three checks of Deliveroo's: a category name of
at least 3 characters, a VAT rate on every item, a picture for the menu (`mealtime_image` or an
item's photo). Then one mealtime (`id` the menu's `ref` or `menu`, name, description, image,
`schedule` from `Menu::$hours` with `day_of_week` 0 for Monday, the category ids), the categories,
the items and the modifiers, and `site_ids: [site_id]`. Every option is an item of the menu:
`price_info.price` in minor units, `plu` the reference, `tax_rate` the item's VAT rate (else the
parent's) with one decimal (`"10.0"`), `allergies` in Deliveroo's list (`GLUTEN` and `NUTS` as
every cereal and nut unless the labels name some: `gluten_wheat`, `nuts_almond`...; `sesame_seeds`,
`sulphur_dioxide_sulphites`; `no_allergens` from a label, `[]` meaning "unknown"), `diets` from the
labels (`dairy_free`, `gluten_free`, `vegan`, `vegetarian`, `halal`, `keto`, `paleo`,
`plant_based`), `contains_alcohol` from the label `alcohol`, the image. A group is a modifier:
`min_selection`, `max_selection` (its `max`, else the number of options), `repeatable` false.
Texts are `{<language>: text}`. Deliveroo processes the upload, then calls the menu webhook;
`Item::$available` is not sent: `setAvailability()` once the menu is in.

**Unavailable items.** With an end, `unavailable` (Deliveroo resets it at the site's next opening:
it takes no date); with none, `hidden`; `available` again.

**Site.** `status()`: `OPEN` is open, `CLOSED` and `READY_TO_OPEN` closed, anything else unknown;
no end. `pause()` sets `CLOSED` (the end and reason are not sent), `resume()` `OPEN`. `setHours()`
replaces the week (`local_start_time`, `local_end_time` per day); `Hours::$exceptions` are not sent.

**Webhooks.** `X-Deliveroo-Hmac-Sha256` must be the hex HMAC-SHA256, with the webhook secret, of
`X-Deliveroo-Sequence-Guid`, a space and the raw body; a missing GUID fails the check. A body with
`body.order` is an order notification, the whole order read, `id` `<order id>:<status>` (the same
on a retry); `rider.status_update` a courier one (`reference` the order id, `PICKED_UP` when the
last rider status is `rider_in_transit`); `menu.upload_result` a menu one (`reference` the menu
id); the others `OTHER`. Those three carry the sequence GUID as `id`.

Not sent: `accept()`'s ready time, the pause's end and reason, `Hours::$exceptions`, the legacy POS
webhooks.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnifood\Deliveroo\DeliverooPlatformFactory;
use Omnifood\Exception\InvalidSignatureException;
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

try {
    $notification = $deliveroo->notify((string) file_get_contents('php://input'), getallheaders());
} catch (InvalidSignatureException) {
    http_response_code(401);
    exit;
}
http_response_code(200);

if ($order = $notification->order) {                       // the whole order, in the webhook
    $deliveroo->accept($order->reference);
    $deliveroo->prepStage($order->reference, 'in_kitchen', 4);
}
```

```yaml
# config/packages/omnifood.yaml
omnifood:
    platforms:
        deliveroo:
            factory: deliveroo
            options:
                client_id: '%env(default::DELIVEROO_CLIENT_ID)%'
                client_secret: '%env(default::DELIVEROO_CLIENT_SECRET)%'
                brand_id: '%env(default::DELIVEROO_BRAND_ID)%'
                site_id: '%env(default::DELIVEROO_SITE_ID)%'
                menu_id: '%env(default::DELIVEROO_MENU_ID)%'
                webhook_secret: '%env(default::DELIVEROO_WEBHOOK_SECRET)%'
```

Not run live (no keys).

## Errors

- 401 and 403: `UnauthorizedException`; 429: `ProviderException` `Too many calls, try again
  later: ...`; any other status of 400 or more: `ProviderException` with Deliveroo's
  `error.message` and `error.code` (the token endpoint's RFC 6749 `error` as the code).
- A network failure: `ProviderException` `Deliveroo could not be reached: ...`.
- A 2xx answer whose body is not JSON (a proxy's or a maintenance page): `ProviderException`
  `An answer that is not JSON (HTTP 200).`, with the status.
- A token answer without `access_token`: `ProviderException` `No token in Deliveroo's answer.`
- `cancel()`: `NotSupportedException`. A menu Deliveroo would refuse: `InvalidMenuException`,
  nothing sent. A webhook not signed or not JSON: `InvalidSignatureException`; no
  `webhook_secret`: `InvalidConfigException`.

No failure carries the client secret and the token: a network failure's message has them masked as
`***` (the key parameters of a URL too), its previous is a copy of the HTTP client's exception (the
same class and code), and no trace of the chain keeps its arguments, where the request's options
stood ([No secret in a
failure](https://github.com/glitchr-omnisense/omnifood/blob/1.x/docs/errors.md#no-secret-in-a-failure)).

## Verified, and not

`Tests/DeliverooPlatformTest.php` checks, on recorded answers written from Deliveroo's public
documentation (https://api-docs.deliveroo.com): the webhook signature against Deliveroo's own
documented example, a new order by webhook, a webhook not signed refused, the rider and menu
webhooks, an order read and the orders listed page by page, a tablet-less site accepting and
rejecting, a tablet site reporting its sync status, no cancellation by the restaurant, the menu in
Deliveroo's shape, a menu Deliveroo would refuse sending nothing, items out of stock, the site
closed, opened and its hours, the sandbox, the errors, and what works without keys. Green in the
family's harness on 2026-10-26. Not run against Deliveroo's API, live or sandbox.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Deliveroo (Roofoods Ltd). Whoever runs it complies with Deliveroo's terms ([API
Deliveroo Developers Terms of Use](https://developers.deliveroo.com/terms)); see the README's
Responsibility section.
