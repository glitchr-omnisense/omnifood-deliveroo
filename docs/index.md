# Deliveroo with Omnifood

## Installation

```sh
composer require glitchr/omnifood omnifood/deliveroo
```

## Configuration

```yaml
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
                sandbox: '%env(bool:default::DELIVEROO_SANDBOX)%'
```

Set the webhook URLs once (Deliveroo's own calls, outside this package):
`PUT /order/v1/integrator/webhooks/order-events` and `.../rider-events` with `{"webhook_url": "https://..."}`,
then the sites' webhook type (`PUT /order/v1/integrator/brands/{brand_id}/sites-config`).

## Receiving an order

```php
try {
    $notification = $deliveroo->notify($request->getContent(), $request->headers->all());
} catch (InvalidSignatureException) {
    return new Response('', 401);
}
if ('order.new' === $notification->event) {
    $order = $notification->order;           // whole: no second call
    $bus->dispatch(new NewOrder('deliveroo', $order->reference, $notification->id));
}

return new Response('', 200);
```

Accept within 10 minutes, then say where the kitchen is:

```php
$deliveroo->accept($order->reference);
$deliveroo->prepStage($order->reference, 'in_kitchen', delay: 4);
$deliveroo->ready($order->reference);        // when someone presses "ready"
```

A site with a Deliveroo tablet (`tablet: true`): the staff accept on the tablet, and `accept()` /
`deny()` tell Deliveroo whether the order reached the till.

## The menu

```php
$deliveroo->pushMenu($menu);                 // every item with its VAT rate; a picture for the menu
// once the menu webhook (menu.upload_result) said it is in:
$deliveroo->setAvailability('gyoza', false); // hidden until said otherwise
```

## The site

```php
$deliveroo->status()->state;                 // open, closed
$deliveroo->pause();                         // closed until resume()
$deliveroo->setHours(new Hours([1 => [['12:00', '14:30'], ['19:00', '22:30']]]));
```

Not verified against the live API: start in Deliveroo's sandbox (`sandbox: true`).
