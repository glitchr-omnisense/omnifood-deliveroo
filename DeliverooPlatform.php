<?php

namespace Omnifood\Deliveroo;

use Omnifood\Auth\RefreshableInterface;
use Omnifood\Channel;
use Omnifood\Exception\InvalidConfigException;
use Omnifood\Exception\InvalidMenuException;
use Omnifood\Exception\InvalidSignatureException;
use Omnifood\Exception\NotSupportedException;
use Omnifood\MenuInterface;
use Omnifood\Model\Allergen;
use Omnifood\Model\Capabilities;
use Omnifood\Model\Customer;
use Omnifood\Model\DenyReason;
use Omnifood\Model\Hours;
use Omnifood\Model\Line;
use Omnifood\Model\Menu\Item;
use Omnifood\Model\Menu\Menu;
use Omnifood\Model\Menu\Modifier as MenuModifier;
use Omnifood\Model\Menu\ModifierGroup;
use Omnifood\Model\Modifier;
use Omnifood\Model\Money;
use Omnifood\Model\Notification;
use Omnifood\Model\NotificationSubject;
use Omnifood\Model\Order;
use Omnifood\Model\OrderStatus;
use Omnifood\Model\OrderType;
use Omnifood\Model\StoreState;
use Omnifood\Model\StoreStatus;
use Omnifood\Model\Token;
use Omnifood\Model\Violation;
use Omnifood\NotifiableInterface;
use Omnifood\OrdersInterface;
use Omnifood\StoreInterface;
use Omnifood\Validator;

/**
 * A restaurant on Deliveroo, through the Partner Platform
 * (https://api-docs.deliveroo.com): the Order API (read, list, accept,
 * reject, prep stages, sync status), the Menu API (upload, items
 * unavailable), the Site API (open or closed, opening hours) and the order
 * and rider webhooks, signed.
 *
 * A tablet-less site accepts and rejects through the API (within 10
 * minutes, 7 in some markets, or Deliveroo rejects the order); a site with
 * a Deliveroo tablet (option "tablet") accepts on the tablet, and the till
 * reports whether the order reached it: there accept() and deny() send the
 * sync status. Deliveroo has no call for the restaurant to cancel an order
 * (its customer service does): cancel() throws NotSupportedException.
 */
final class DeliverooPlatform implements OrdersInterface, MenuInterface, StoreInterface, NotifiableInterface, RefreshableInterface
{
    /** Deliveroo lists each cereal and each nut; Omnifood's GLUTEN and NUTS are the regulation's families. */
    public const CEREALS = ['gluten_wheat', 'gluten_rye', 'gluten_barley', 'gluten_oats'];
    public const NUTS = ['nuts_almond', 'nuts_hazelnut', 'nuts_walnut', 'nuts_cashew_nut', 'nuts_pecan_nut', 'nuts_brazil_nut', 'nuts_pistachio_nut', 'nuts_macadamia_or_queensland_nut'];
    public const DIETS = ['dairy_free', 'gluten_free', 'vegan', 'vegetarian', 'halal', 'keto', 'paleo', 'plant_based'];

    private const ALLERGENS = [
        'crustaceans' => 'crustaceans', 'eggs' => 'eggs', 'fish' => 'fish', 'peanuts' => 'peanuts', 'soybeans' => 'soybeans',
        'milk' => 'milk', 'celery' => 'celery', 'mustard' => 'mustard', 'sesame' => 'sesame_seeds',
        'sulphites' => 'sulphur_dioxide_sulphites', 'lupin' => 'lupin', 'molluscs' => 'molluscs',
    ];

    private const DAYS = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];

    public function __construct(
        private readonly Api $api,
        private readonly ?string $brandId = null,
        private readonly ?string $siteId = null,
        private readonly ?string $menuId = null,
        private readonly ?string $webhookSecret = null,
        private readonly bool $tablet = false,
        private readonly string $language = 'fr',
        private readonly ?string $mealtimeImage = null,
        private readonly Validator $validator = new Validator(),
    ) {
    }

    public function getName(): string
    {
        return 'deliveroo';
    }

    public function getChannel(): Channel
    {
        return Channel::DELIVEROO;
    }

    /**
     * A modifier's options are items of the menu (with their price, VAT
     * rate and allergens); names 2 to 120 characters (categories 3), the
     * descriptions 500. The site closes and opens, with no end set.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            orderTypes: [OrderType::DELIVERY, OrderType::PICKUP, OrderType::DINE_IN],
            acceptanceDelay: 600,
            readyAt: false,
            modifierDepth: 2,
            allergens: true,
            photos: true,
            vatRates: true,
            nameMaxLength: 120,
            descriptionMaxLength: 500,
            pauseUntil: false,
            modifiersAreItems: true,
        );
    }

    public function order(string $ref): Order
    {
        return $this->toOrder($this->api->request('GET', '/order/v2/orders/'.rawurlencode($ref)));
    }

    /**
     * The site's orders placed since then (the last 24 hours by default;
     * Deliveroo keeps 30 days), newest first, page after page.
     */
    public function orders(?\DateTimeImmutable $since = null): array
    {
        $since ??= new \DateTimeImmutable('-1 day');
        $orders = [];
        $cursor = null;
        do {
            $page = $this->api->request('GET', '/order/v2/brand/'.rawurlencode($this->brand()).'/restaurant/'.rawurlencode($this->site()).'/orders', [
                'start_date' => $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'cursor' => $cursor,
            ]);
            foreach ((array) ($page['orders'] ?? []) as $order) {
                $orders[] = $this->toOrder($order);
            }
            $cursor = ($page['next'] ?? '') ?: null;
        } while (null !== $cursor);
        usort($orders, static fn (Order $a, Order $b) => $b->placedAt <=> $a->placedAt);

        return $orders;
    }

    /** $readyAt is not sent: Deliveroo's own prepare_for stands. */
    public function accept(string $ref, ?\DateTimeImmutable $readyAt = null): void
    {
        if ($this->tablet) {
            $this->syncStatus($ref, true);

            return;
        }
        $this->api->request('PATCH', '/order/v1/orders/'.rawurlencode($ref), [], ['status' => 'accepted']);
    }

    public function deny(string $ref, DenyReason $reason, ?string $note = null): void
    {
        if ($this->tablet) {
            $this->syncStatus($ref, false, match ($reason) {
                DenyReason::ITEM_UNAVAILABLE => 'items_out_of_stock',
                DenyReason::PRICING => 'price_mismatched',
                DenyReason::CLOSED => 'location_offline',
                default => 'other',
            }, $note);

            return;
        }
        $this->api->request('PATCH', '/order/v1/orders/'.rawurlencode($ref), [], array_filter([
            'status' => 'rejected',
            'reject_reason' => match ($reason) {
                DenyReason::CLOSED => 'closing_early',
                DenyReason::TOO_BUSY => 'busy',
                DenyReason::ITEM_UNAVAILABLE => 'ingredient_unavailable',
                default => 'other',
            },
            'notes' => $note,
        ], static fn ($v) => null !== $v));
    }

    /**
     * Ready for collection: Deliveroo asks that it be sent when someone
     * presses "ready", never on a timer.
     */
    public function ready(string $ref): void
    {
        $this->prepStage($ref, 'ready_for_collection');
    }

    public function cancel(string $ref, DenyReason $reason, ?string $note = null): void
    {
        throw NotSupportedException::operation($this->getName(), 'cancel an order (Deliveroo has no such call for the restaurant: its customer service cancels, and the webhook says so)');
    }

    /**
     * Where the kitchen is: in_kitchen (with a delay of 0, 2, 4, 6, 8 or 10
     * minutes), ready_for_collection_soon, ready_for_collection, collected.
     */
    public function prepStage(string $ref, string $stage, ?int $delay = null): void
    {
        $this->api->request('POST', '/order/v1/orders/'.rawurlencode($ref).'/prep_stage', [], array_filter([
            'stage' => $stage,
            'occurred_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'delay' => $delay,
        ], static fn ($v) => null !== $v));
    }

    /** For a tablet site: whether the order reached the till (failed: its reason, Deliveroo's word). */
    public function syncStatus(string $ref, bool $succeeded, ?string $reason = null, ?string $notes = null): void
    {
        $this->api->request('POST', '/order/v1/orders/'.rawurlencode($ref).'/sync_status', [], array_filter([
            'status' => $succeeded ? 'succeeded' : 'failed',
            'reason' => $succeeded ? null : ($reason ?? 'other'),
            'notes' => $notes,
            'occurred_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ], static fn ($v) => null !== $v));
    }

    /**
     * The menu uploaded to the site (Deliveroo processes it, then calls the
     * menu webhook). Items marked unavailable in it are not sent so:
     * setAvailability() once the webhook said the menu is in.
     */
    public function pushMenu(Menu $menu): void
    {
        $violations = $this->validator->validate($menu, $this->capabilities());
        foreach ($menu->categories as $i => $category) {
            if (mb_strlen($category->name) < 3) {
                $violations[] = new Violation("categories[$i].name", 'At least 3 characters.');
            }
        }
        foreach ($menu->items() as $item) {
            if (null === $item->vatRate) {
                $violations[] = new Violation('items['.$item->ref.'].vatRate', 'Deliveroo requires each item\'s VAT rate.');
            }
        }
        if (!$this->mealtimeImage && !array_filter($menu->items(), static fn (Item $i) => null !== $i->photo)) {
            $violations[] = new Violation('photo', 'Deliveroo requires a picture for the menu: the option mealtime_image, or an item\'s photo.');
        }
        if ($violations) {
            throw new InvalidMenuException($this->getName(), $violations);
        }
        $this->api->request('PUT', '/menu/v1/brands/'.rawurlencode($this->brand()).'/menus/'.rawurlencode($this->menu()), [], $this->menuPayload($menu));
    }

    /**
     * The JSON the upload takes for $menu, for this site.
     *
     * @return array<string, mixed>
     */
    public function menuPayload(Menu $menu): array
    {
        $items = [];
        $modifiers = [];
        foreach ($menu->items() as $item) {
            $this->collect($item, $item->vatRate, $items, $modifiers);
        }
        $schedule = [];
        if (null !== $menu->hours) {
            foreach (self::DAYS as $n => $day) {
                if ($ranges = $menu->hours->day($n)) {
                    $schedule[] = ['day_of_week' => $n - 1, 'time_periods' => array_map(static fn (array $r) => ['start' => $r[0], 'end' => $r[1]], $ranges)];
                }
            }
        }
        $photo = $this->mealtimeImage ?? current(array_filter(array_map(static fn (Item $i) => $i->photo, $menu->items())));

        return [
            'menu' => [
                'mealtimes' => [array_filter([
                    'id' => $menu->ref ?? 'menu',
                    'name' => $this->text($menu->name),
                    'description' => null !== $menu->description ? $this->text($menu->description) : null,
                    'image' => ['url' => (string) $photo],
                    'schedule' => $schedule,
                    'category_ids' => array_map(static fn ($c) => $c->ref, $menu->categories),
                ], static fn ($v) => null !== $v)],
                'categories' => array_map(fn ($c) => array_filter([
                    'id' => $c->ref,
                    'name' => $this->text($c->name),
                    'description' => null !== $c->description ? $this->text($c->description) : null,
                    'item_ids' => array_values(array_unique(array_map(static fn (Item $i) => $i->ref, $c->items))),
                ], static fn ($v) => null !== $v), $menu->categories),
                'items' => array_values($items),
                'modifiers' => array_values($modifiers),
            ],
            'site_ids' => [$this->site()],
        ];
    }

    /**
     * Unavailable until the site's next opening (Deliveroo's "unavailable",
     * reset each morning) when $until is given - Deliveroo takes no date -,
     * hidden until said otherwise when it is not.
     */
    public function setAvailability(string $itemRef, bool $available, ?\DateTimeImmutable $until = null): void
    {
        $this->api->request('POST', '/menu/v1/brands/'.rawurlencode($this->brand()).'/menus/'.rawurlencode($this->menu()).'/item_unavailabilities/'.rawurlencode($this->site()), [], [
            'item_unavailabilities' => [['item_id' => $itemRef, 'status' => $available ? 'available' : (null !== $until ? 'unavailable' : 'hidden')]],
        ]);
    }

    public function status(): StoreStatus
    {
        $data = $this->api->request('GET', $this->sitePath('/status'));

        return new StoreStatus(match ($data['status'] ?? null) {
            'OPEN' => StoreState::OPEN,
            'CLOSED', 'READY_TO_OPEN' => StoreState::CLOSED,
            default => StoreState::UNKNOWN,
        }, raw: $data);
    }

    /** Closed now; Deliveroo takes no end: resume() opens it again ($until and $reason are not sent). */
    public function pause(?\DateTimeImmutable $until = null, ?string $reason = null): void
    {
        $this->api->request('PUT', $this->sitePath('/status'), [], ['status' => 'CLOSED']);
    }

    public function resume(): void
    {
        $this->api->request('PUT', $this->sitePath('/status'), [], ['status' => 'OPEN']);
    }

    /** The week replaced whole; Hours::$exceptions are not sent (Deliveroo's days off are not mapped). */
    public function setHours(Hours $hours): void
    {
        $week = [];
        foreach (self::DAYS as $n => $day) {
            $week[$day] = array_map(static fn (array $r) => ['local_start_time' => $r[0], 'local_end_time' => $r[1]], $hours->day($n));
        }
        $this->api->request('POST', $this->sitePath('/opening_hours'), [], ['opening_hours' => $week]);
    }

    /**
     * An order event (order.new, order.status_update), a rider event
     * (rider.status_update) or the menu's upload result
     * (menu.upload_result), checked: X-Deliveroo-Hmac-Sha256 is the hex
     * HMAC-SHA256, with the webhook secret, of X-Deliveroo-Sequence-Guid, a
     * space and the raw body. Events come out of order and more than once:
     * an order's Notification::$id is its id and status, the same on a
     * retry; the others' the sequence GUID.
     */
    public function notify(string $body, array $headers): Notification
    {
        $headers = array_change_key_case(array_map(static fn ($v) => \is_array($v) ? (string) reset($v) : (string) $v, $headers));
        $secret = $this->webhookSecret ?: throw new InvalidConfigException('The "deliveroo" platform needs: webhook_secret, to check its webhooks.');
        $guid = $headers['x-deliveroo-sequence-guid'] ?? '';
        if ('' === $guid || !hash_equals(hash_hmac('sha256', $guid.' '.$body, $secret), strtolower($headers['x-deliveroo-hmac-sha256'] ?? ''))) {
            throw new InvalidSignatureException($this->getName(), 'The X-Deliveroo-Hmac-Sha256 signature does not match the body.');
        }
        $data = json_decode($body, true);
        if (!\is_array($data)) {
            throw new InvalidSignatureException($this->getName(), 'The body is not JSON.');
        }
        $event = (string) ($data['event'] ?? 'unknown');
        $payload = (array) ($data['body'] ?? []);

        if (\is_array($payload['order'] ?? null)) {
            $order = $this->toOrder($payload['order']);

            return new Notification(Channel::DELIVEROO, $event, NotificationSubject::ORDER, $order->reference, $order->reference.':'.($payload['order']['status'] ?? $event), $order, null, $order->status, null, $order->store, $data);
        }
        if ('rider.status_update' === $event) {
            $rider = (array) (($payload['riders'] ?? [])[0] ?? []);
            $log = (array) ($rider['status_log'] ?? []);
            $last = $log ? (string) (end($log)['status'] ?? '') : '';

            return new Notification(Channel::DELIVEROO, $event, NotificationSubject::COURIER, isset($payload['order_id']) ? (string) $payload['order_id'] : null, $guid,
                status: 'rider_in_transit' === $last ? OrderStatus::PICKED_UP : null, raw: $data);
        }
        if ('menu.upload_result' === $event) {
            return new Notification(Channel::DELIVEROO, $event, NotificationSubject::MENU, $payload['menu_upload_result']['menu_id'] ?? null, $guid, raw: $data);
        }

        return new Notification(Channel::DELIVEROO, $event, NotificationSubject::OTHER, null, $guid, raw: $data);
    }

    public function token(): ?Token
    {
        return $this->api->heldToken();
    }

    /** A new token: it lives 5 minutes, and the platform asks for one itself when it needs it. */
    public function refresh(): Token
    {
        return $this->api->refresh();
    }

    /**
     * An order as Deliveroo gives it - the order webhook's body.order, or
     * GET /order/v2/orders/{id} - as an Order.
     *
     * @param array<string, mixed> $o
     */
    public function toOrder(array $o): Order
    {
        $status = match ($o['status'] ?? null) {
            'pending', 'placed' => OrderStatus::NEW,
            'accepted', 'confirmed' => OrderStatus::ACCEPTED,
            'rejected' => OrderStatus::DENIED,
            'canceled' => OrderStatus::CANCELLED,
            default => OrderStatus::UNKNOWN,
        };
        $stages = array_map(static fn ($s) => \is_array($s) ? ($s['stage'] ?? null) : $s, (array) ($o['prep_stages'] ?? []));
        if (OrderStatus::ACCEPTED === $status && \in_array('collected', $stages, true)) {
            $status = OrderStatus::PICKED_UP;
        } elseif (OrderStatus::ACCEPTED === $status && \in_array('ready_for_collection', $stages, true)) {
            $status = OrderStatus::READY;
        }
        $placedAt = null;
        foreach ((array) ($o['status_log'] ?? []) as $entry) {
            if ('placed' === ($entry['status'] ?? null) && isset($entry['at'])) {
                $placedAt = self::date((string) $entry['at']);
            }
        }
        $delivery = \is_array($o['delivery'] ?? null) ? $o['delivery'] : [];
        $customer = \is_array($o['customer'] ?? null) ? $o['customer'] : [];
        $fees = array_sum(array_map(static fn ($m) => \is_array($m) ? (int) ($m['fractional'] ?? 0) : 0, [$o['bag_fee'] ?? null, $o['surcharge'] ?? null, $delivery['delivery_fee'] ?? null]));
        $currency = $o['total_price']['currency_code'] ?? $o['subtotal']['currency_code'] ?? 'EUR';
        $address = array_filter([$delivery['line1'] ?? null, $delivery['line2'] ?? null, trim(($delivery['postcode'] ?? '').' '.($delivery['city'] ?? ''))], static fn ($v) => null !== $v && '' !== trim((string) $v));

        return new Order(
            Channel::DELIVEROO,
            (string) $o['id'],
            isset($o['display_id']) ? (string) $o['display_id'] : null,
            match ($o['fulfillment_type'] ?? null) {
                'customer' => OrderType::PICKUP,
                'table_service' => OrderType::DINE_IN,
                default => OrderType::DELIVERY,
            },
            $status,
            array_map($this->line(...), array_values((array) ($o['items'] ?? []))),
            $customer || $delivery ? new Customer(
                ($customer['first_name'] ?? $delivery['customer_name'] ?? null) ?: null,
                ($customer['contact_number'] ?? $delivery['contact_number'] ?? null) ?: null,
                ($customer['contact_access_code'] ?? $delivery['contact_access_code'] ?? null) ?: null,
                null,
                $address ? implode(', ', $address) : null,
            ) : null,
            self::money($o['total_price'] ?? null),
            self::money($o['subtotal'] ?? null),
            self::money($o['offer_discount'] ?? null),
            $fees ? Money::of($fees, (string) $currency) : null,
            null,
            $placedAt,
            self::date($o['prepare_for'] ?? null),
            self::date($delivery['deliver_by'] ?? null),
            null,
            implode("\n", array_filter([$o['order_notes'] ?? null, $delivery['note'] ?? $delivery['delivery_notes'] ?? null, isset($o['table_number']) && '' !== $o['table_number'] ? 'Table '.$o['table_number'] : null], static fn ($v) => null !== $v && '' !== $v)) ?: null,
            isset($o['location_id']) ? (string) $o['location_id'] : null,
            null,
            $o,
        );
    }

    /** @param array<string, mixed> $i */
    private function line(array $i): Line
    {
        return new Line(
            (string) ($i['name'] ?? $i['operational_name'] ?? ''),
            (int) ($i['quantity'] ?? 1),
            self::money($i['unit_price'] ?? null),
            self::money($i['total_price'] ?? null),
            ($i['pos_item_id'] ?? '') ?: null,
            null,
            array_map($this->modifier(...), array_values((array) ($i['modifiers'] ?? []))),
        );
    }

    /** @param array<string, mixed> $m */
    private function modifier(array $m): Modifier
    {
        return new Modifier(
            (string) ($m['name'] ?? $m['operational_name'] ?? ''),
            (int) ($m['quantity'] ?? 1),
            self::money($m['unit_price'] ?? null),
            ($m['pos_item_id'] ?? '') ?: null,
            null,
            array_map($this->modifier(...), array_values((array) ($m['modifiers'] ?? []))),
        );
    }

    /**
     * An item, its modifier groups and their options (items too), into the
     * menu's flat lists.
     *
     * @param array<string, array<string, mixed>> $items
     * @param array<string, array<string, mixed>> $modifiers
     */
    private function collect(Item|MenuModifier $item, ?float $vat, array &$items, array &$modifiers): void
    {
        $vat = $item->vatRate ?? $vat;
        $labels = $item instanceof Item ? array_map('strtolower', $item->labels) : [];
        $allergies = [];
        foreach ($item->allergens as $allergen) {
            $allergies = array_merge($allergies, match ($allergen) {
                Allergen::GLUTEN => array_values(array_intersect(self::CEREALS, $labels)) ?: self::CEREALS,
                Allergen::NUTS => array_values(array_intersect(self::NUTS, $labels)) ?: self::NUTS,
                default => [self::ALLERGENS[$allergen->value]],
            });
        }
        $items[$item->ref] ??= array_filter([
            'id' => $item->ref,
            'name' => $this->text($item->name),
            'description' => $item instanceof Item && null !== $item->description ? $this->text($item->description) : null,
            'price_info' => ['price' => $item->price?->amount ?? 0],
            'plu' => $item->ref,
            'tax_rate' => null !== $vat ? \sprintf('%.1f', $vat) : null,
            // [] says "unknown" to Deliveroo: "no_allergens" is said by the item's labels.
            'allergies' => $allergies ?: (\in_array('no_allergens', $labels, true) ? ['no_allergens'] : []),
            'diets' => array_values(array_intersect(self::DIETS, $labels)),
            'contains_alcohol' => \in_array('alcohol', $labels, true),
            'modifier_ids' => array_map(static fn (ModifierGroup $g) => $g->ref, $item->modifierGroups),
            'image' => $item instanceof Item && null !== $item->photo ? ['url' => $item->photo] : null,
        ], static fn ($v) => null !== $v);
        foreach ($item->modifierGroups as $group) {
            $modifiers[$group->ref] ??= [
                'id' => $group->ref,
                'name' => $this->text($group->name),
                'min_selection' => $group->min,
                'max_selection' => $group->max ?? \count($group->modifiers),
                'repeatable' => false,
                'item_ids' => array_map(static fn (MenuModifier $m) => $m->ref, $group->modifiers),
            ];
            foreach ($group->modifiers as $option) {
                $this->collect($option, $vat, $items, $modifiers);
            }
        }
    }

    /** @return array<string, string> */
    private function text(string $text): array
    {
        return [$this->language => $text];
    }

    private function sitePath(string $path): string
    {
        return '/site/v1/brands/'.rawurlencode($this->brand()).'/sites/'.rawurlencode($this->site()).$path;
    }

    private function brand(): string
    {
        return $this->brandId ?: throw new InvalidConfigException('The "deliveroo" platform needs: brand_id.');
    }

    private function site(): string
    {
        return $this->siteId ?: throw new InvalidConfigException('The "deliveroo" platform needs: site_id.');
    }

    private function menu(): string
    {
        return $this->menuId ?: throw new InvalidConfigException('The "deliveroo" platform needs: menu_id.');
    }

    private static function money(mixed $money): ?Money
    {
        return \is_array($money) && isset($money['fractional'], $money['currency_code']) ? Money::of((int) $money['fractional'], (string) $money['currency_code']) : null;
    }

    private static function date(?string $date): ?\DateTimeImmutable
    {
        try {
            return $date ? new \DateTimeImmutable($date) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
