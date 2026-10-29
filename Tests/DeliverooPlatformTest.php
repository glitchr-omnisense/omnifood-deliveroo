<?php

namespace Omnifood\Deliveroo\Tests;

use Omnifood\Deliveroo\DeliverooPlatform;
use Omnifood\Deliveroo\DeliverooPlatformFactory;
use Omnifood\Exception\InvalidConfigException;
use Omnifood\Exception\InvalidMenuException;
use Omnifood\Exception\InvalidSignatureException;
use Omnifood\Exception\NotSupportedException;
use Omnifood\Exception\ProviderException;
use Omnifood\Exception\UnauthorizedException;
use Omnifood\Model\Allergen;
use Omnifood\Model\DenyReason;
use Omnifood\Model\Hours;
use Omnifood\Model\Menu\Category;
use Omnifood\Model\Menu\Item;
use Omnifood\Model\Menu\Menu;
use Omnifood\Model\Menu\Modifier;
use Omnifood\Model\Menu\ModifierGroup;
use Omnifood\Model\Money;
use Omnifood\Model\NotificationSubject;
use Omnifood\Model\OrderStatus;
use Omnifood\Model\OrderType;
use Omnifood\Model\StoreState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DeliverooPlatformTest extends TestCase
{
    /** @var list<array{string, string, array<string, string>, array<string, string>, mixed}> method, host+path, query, headers, body */
    private array $calls = [];

    private int $tokens = 0;

    private function platform(array $options = [], ?\Closure $answer = null): DeliverooPlatform
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $path = parse_url($url, \PHP_URL_HOST).rawurldecode((string) parse_url($url, \PHP_URL_PATH));
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $headers = [];
            foreach ($options['headers'] as $header) {
                [$name, $value] = explode(': ', $header, 2);
                $headers[strtolower($name)] = $value;
            }
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
            if (str_ends_with($path, '/oauth2/token')) {
                parse_str($body, $form);
                $this->calls[] = [$method, $path, [], $headers, $form];

                return self::json(['access_token' => 'jwt-'.++$this->tokens, 'expires_in' => 300, 'token_type' => 'Bearer']);
            }
            $this->calls[] = [$method, $path, $query, $headers, '' !== $body ? json_decode($body, true) : null];
            if ($answer && null !== ($response = $answer($method, $path, $query))) {
                return $response;
            }

            return match (true) {
                'GET' === $method && str_ends_with($path, '/order/v2/orders/gb:0a1b') => self::json(self::order()),
                'GET' === $method && str_ends_with($path, '/order/v2/brand/brand-1/restaurant/site-1/orders') => self::json(null === ($query['cursor'] ?? null)
                    ? ['orders' => [self::order('gb:1', '2026-10-04T11:00:00Z')], 'next' => 'c2']
                    : ['orders' => [self::order('gb:2', '2026-10-04T12:00:00Z')], 'next' => '']),
                'GET' === $method && str_ends_with($path, '/status') => self::json(['status' => 'READY_TO_OPEN']),
                'POST' === $method && str_ends_with($path, '/prep_stage') => self::json([]),
                default => new MockResponse('', ['http_code' => 204]),
            };
        });

        return (new DeliverooPlatformFactory($http))->create($options + ['client_id' => 'cid', 'client_secret' => 'secret', 'brand_id' => 'brand-1', 'site_id' => 'site-1', 'menu_id' => 'menu-1', 'webhook_secret' => 'abc123']);
    }

    private static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status]);
    }

    private static function eur(int $fractional): array
    {
        return ['fractional' => $fractional, 'currency_code' => 'EUR'];
    }

    private static function order(string $id = 'gb:0a1b', string $placed = '2026-10-04T12:00:00Z', string $status = 'placed'): array
    {
        return [
            'id' => $id, 'order_number' => '1234567890', 'location_id' => 'site-1', 'brand_id' => 'brand-1', 'display_id' => '0A1B', 'status' => $status,
            'status_log' => [['at' => $placed, 'status' => 'placed']], 'fulfillment_type' => 'deliveroo', 'order_notes' => 'No coriander', 'cutlery_notes' => 'No cutlery', 'asap' => true,
            'prepare_for' => '2026-10-04T12:20:00Z', 'start_preparing_at' => '2026-10-04T12:05:00Z', 'subtotal' => self::eur(2200), 'total_price' => self::eur(2350), 'offer_discount' => self::eur(200), 'bag_fee' => self::eur(50),
            'items' => [['pos_item_id' => 'ramen', 'name' => 'Shoyu ramen', 'quantity' => 2, 'unit_price' => self::eur(1100), 'total_price' => self::eur(2200), 'menu_unit_price' => self::eur(1100),
                'modifiers' => [['pos_item_id' => 'egg', 'name' => 'Egg', 'quantity' => 1, 'unit_price' => self::eur(150), 'total_price' => self::eur(150), 'modifiers' => []]]]],
            'customer' => ['first_name' => 'Aiko', 'contact_number' => '+44 20 3808 0000', 'contact_access_code' => '123 456 789'],
        ];
    }

    private static function signed(string $body, string $guid = '1174efedab186000', string $secret = 'abc123'): array
    {
        return ['X-Deliveroo-Sequence-Guid' => $guid, 'X-Deliveroo-Hmac-Sha256' => hash_hmac('sha256', $guid.' '.$body, $secret), 'X-Deliveroo-Payload-Type' => 'event/order.new'];
    }

    public function testTheSignatureIsDeliveroosDocumentedExample(): void
    {
        $body = "{\n    \"hello\": \"world\"\n}";
        $headers = ['x-deliveroo-sequence-guid' => '1174efedab186000', 'x-deliveroo-hmac-sha256' => '3ecb144a17c06b81b6cd95c29349927f05f3d6c9b6c1821d226ed0100a4fefa6'];

        $notification = $this->platform()->notify($body, $headers);

        self::assertSame(NotificationSubject::OTHER, $notification->subject, 'checked, and nothing Omnifood knows');
        self::assertSame('1174efedab186000', $notification->id);
    }

    public function testANewOrderByWebhook(): void
    {
        $body = (string) json_encode(['event' => 'order.new', 'body' => ['order' => self::order()]]);
        $notification = $this->platform()->notify($body, self::signed($body));

        self::assertSame(['order.new', NotificationSubject::ORDER, 'gb:0a1b', 'gb:0a1b:placed', OrderStatus::NEW], [$notification->event, $notification->subject, $notification->reference, $notification->id, $notification->status]);
        $order = $notification->order;
        self::assertSame('0A1B', $order->displayId);
        self::assertSame(OrderType::DELIVERY, $order->type);
        self::assertSame('site-1', $order->store);
        self::assertSame(2, $order->lines[0]->quantity);
        self::assertSame('ramen', $order->lines[0]->posRef);
        self::assertTrue(Money::of(1100, 'EUR')->equals($order->lines[0]->unitPrice));
        self::assertSame('egg', $order->lines[0]->modifiers[0]->posRef);
        self::assertSame([2350, 2200, 200, 50], [$order->total->amount, $order->subtotal->amount, $order->discount->amount, $order->fees->amount]);
        self::assertEquals(new \DateTimeImmutable('2026-10-04T12:00:00Z'), $order->placedAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-04T12:20:00Z'), $order->pickupAt);
        self::assertSame(['Aiko', '+44 20 3808 0000', '123 456 789'], [$order->customer->name, $order->customer->phone, $order->customer->phoneCode]);
        self::assertSame('No coriander', $order->note);
    }

    public function testAWebhookNotSignedIsRefused(): void
    {
        $body = '{"event":"order.new","body":{}}';
        $platform = $this->platform();

        foreach ([['x-deliveroo-sequence-guid' => 'g'], self::signed($body, 'g', 'other'), self::signed($body.' ', 'g'), ['x-deliveroo-hmac-sha256' => hash_hmac('sha256', ' '.$body, 'abc123')]] as $i => $headers) {
            try {
                $platform->notify($body, $headers);
                self::fail("Case $i refused.");
            } catch (InvalidSignatureException) {
            }
        }
        $this->expectException(InvalidConfigException::class);
        $this->platform(['webhook_secret' => null])->notify($body, self::signed($body));
    }

    public function testTheRiderAndTheMenuWebhooks(): void
    {
        $platform = $this->platform();
        $rider = (string) json_encode(['event' => 'rider.status_update', 'body' => ['order_id' => 'gb:0a1b', 'riders' => [['full_name' => 'Deliveroo Rider', 'status_log' => [['at' => '2026-10-04T12:18:00Z', 'status' => 'rider_arrived'], ['at' => '2026-10-04T12:22:00Z', 'status' => 'rider_in_transit']]]]]]);
        $notification = $platform->notify($rider, self::signed($rider, 'g-1'));
        self::assertSame([NotificationSubject::COURIER, 'gb:0a1b', OrderStatus::PICKED_UP, 'g-1'], [$notification->subject, $notification->reference, $notification->status, $notification->id]);

        $menu = '{"event":"menu.upload_result","body":{"menu_upload_result":{"http_status":200,"brand_id":"brand-1","menu_id":"menu-1","site_ids":["site-1"],"errors":{}}}}';
        $notification = $platform->notify($menu, self::signed($menu, 'g-2'));
        self::assertSame([NotificationSubject::MENU, 'menu-1'], [$notification->subject, $notification->reference]);
    }

    public function testAnOrderReadAndTheOrdersListedPageByPage(): void
    {
        $platform = $this->platform();
        self::assertSame('gb:0a1b', $platform->order('gb:0a1b')->reference);
        [$token, $get] = $this->calls;
        self::assertSame(['POST', 'auth.developers.deliveroo.com/oauth2/token', ['grant_type' => 'client_credentials']], [$token[0], $token[1], $token[4]]);
        self::assertSame('Basic '.base64_encode('cid:secret'), $token[3]['authorization']);
        self::assertSame(['GET', 'api.developers.deliveroo.com/order/v2/orders/gb:0a1b', 'Bearer jwt-1'], [$get[0], $get[1], $get[3]['authorization']]);

        $orders = $platform->orders(new \DateTimeImmutable('2026-10-04T10:00:00+02:00'));
        self::assertSame(['gb:2', 'gb:1'], array_map(static fn ($o) => $o->reference, $orders), 'newest first');
        self::assertSame(['start_date' => '2026-10-04T08:00:00Z'], $this->calls[2][2]);
        self::assertSame(['start_date' => '2026-10-04T08:00:00Z', 'cursor' => 'c2'], $this->calls[3][2]);
        self::assertSame(1, $this->tokens, 'the token kept while it lives');
    }

    public function testATablelessSiteAcceptsAndRejects(): void
    {
        $platform = $this->platform();
        $platform->accept('gb:1', new \DateTimeImmutable('+20 minutes'));
        $platform->deny('gb:2', DenyReason::TOO_BUSY, 'Full room');
        $platform->ready('gb:1');

        self::assertSame(['PATCH', ['status' => 'accepted']], [$this->calls[1][0], $this->calls[1][4]]);
        self::assertSame(['status' => 'rejected', 'reject_reason' => 'busy', 'notes' => 'Full room'], $this->calls[2][4]);
        self::assertStringEndsWith('/prep_stage', $this->calls[3][1]);
        self::assertSame('ready_for_collection', $this->calls[3][4]['stage']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $this->calls[3][4]['occurred_at']);
    }

    public function testATabletSiteReportsTheSyncStatus(): void
    {
        $platform = $this->platform(['tablet' => true]);
        $platform->accept('gb:1');
        $platform->deny('gb:2', DenyReason::ITEM_UNAVAILABLE);

        self::assertStringEndsWith('/order/v1/orders/gb:1/sync_status', $this->calls[1][1]);
        self::assertSame('succeeded', $this->calls[1][4]['status']);
        self::assertArrayNotHasKey('reason', $this->calls[1][4]);
        self::assertSame(['failed', 'items_out_of_stock'], [$this->calls[2][4]['status'], $this->calls[2][4]['reason']]);
    }

    public function testNoCancellationByTheRestaurant(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->platform()->cancel('gb:1', DenyReason::OTHER);
    }

    public function testTheMenuInDeliveroosShape(): void
    {
        $platform = $this->platform();
        $sauce = new ModifierGroup('sauce', 'Sauce', [new Modifier('soy', 'Soy'), new Modifier('ponzu', 'Ponzu', Money::of(50, 'EUR'))], 1, 1);
        $menu = new Menu('Dîner', [
            new Category('mains', 'Plats', [new Item('ramen', 'Shoyu ramen', Money::of(1400, 'EUR'), 'Bouillon de poulet', 10.0, [Allergen::GLUTEN, Allergen::EGGS], 'https://site.example/ramen.jpg', [$sauce], labels: ['gluten_wheat'])]),
            new Category('drinks', 'Boissons', [new Item('sake', 'Saké', Money::of(900, 'EUR'), vatRate: 20.0, labels: ['alcohol', 'vegan', 'no_allergens'])]),
        ], 'EUR', new Hours([1 => [['12:00', '14:30']], 5 => [['19:00', '23:00']]]), 'dinner');

        $platform->pushMenu($menu);

        [$method, $path, , , $body] = $this->calls[1];
        self::assertSame(['PUT', 'api.developers.deliveroo.com/menu/v1/brands/brand-1/menus/menu-1'], [$method, $path]);
        self::assertSame(['site-1'], $body['site_ids']);
        $sent = $body['menu'];
        self::assertSame(['id' => 'dinner', 'name' => ['fr' => 'Dîner'], 'image' => ['url' => 'https://site.example/ramen.jpg'], 'schedule' => [
            ['day_of_week' => 0, 'time_periods' => [['start' => '12:00', 'end' => '14:30']]],
            ['day_of_week' => 4, 'time_periods' => [['start' => '19:00', 'end' => '23:00']]],
        ], 'category_ids' => ['mains', 'drinks']], $sent['mealtimes'][0]);
        self::assertSame(['id' => 'mains', 'name' => ['fr' => 'Plats'], 'item_ids' => ['ramen']], $sent['categories'][0]);
        $items = array_column($sent['items'], null, 'id');
        self::assertSame(['ramen', 'soy', 'ponzu', 'sake'], array_keys($items), 'the options are items too');
        self::assertSame(['price' => 1400], $items['ramen']['price_info']);
        self::assertSame('10.0', $items['ramen']['tax_rate']);
        self::assertSame(['gluten_wheat', 'eggs'], $items['ramen']['allergies']);
        self::assertSame(['sauce'], $items['ramen']['modifier_ids']);
        self::assertSame(['price' => 50], $items['ponzu']['price_info']);
        self::assertSame('10.0', $items['ponzu']['tax_rate'], 'an option at its item\'s rate');
        self::assertSame([], $items['soy']['allergies'], 'unknown');
        self::assertSame(['no_allergens'], $items['sake']['allergies']);
        self::assertSame(['vegan'], $items['sake']['diets']);
        self::assertTrue($items['sake']['contains_alcohol']);
        self::assertSame('20.0', $items['sake']['tax_rate']);
        self::assertSame([['id' => 'sauce', 'name' => ['fr' => 'Sauce'], 'min_selection' => 1, 'max_selection' => 1, 'repeatable' => false, 'item_ids' => ['soy', 'ponzu']]], $sent['modifiers']);
    }

    public function testAMenuDeliverooWouldRefuseSendsNothing(): void
    {
        $platform = $this->platform();
        try {
            $platform->pushMenu(new Menu('Dîner', [new Category('m', 'Té', [new Item('ramen', 'Ramen', Money::of(1400, 'EUR'))])]));
            self::fail('Refused.');
        } catch (InvalidMenuException $e) {
            self::assertSame(['categories[0].name', 'items[ramen].vatRate', 'photo'], array_map(static fn ($v) => $v->path, $e->violations));
        }
        self::assertSame([], $this->calls);
    }

    public function testItemsOutOfStock(): void
    {
        $platform = $this->platform();
        $platform->setAvailability('ramen', false, new \DateTimeImmutable('tomorrow 11:00'));
        $platform->setAvailability('sake', false);
        $platform->setAvailability('ramen', true);

        self::assertSame('api.developers.deliveroo.com/menu/v1/brands/brand-1/menus/menu-1/item_unavailabilities/site-1', $this->calls[1][1]);
        self::assertSame(['item_unavailabilities' => [['item_id' => 'ramen', 'status' => 'unavailable']]], $this->calls[1][4]);
        self::assertSame('hidden', $this->calls[2][4]['item_unavailabilities'][0]['status']);
        self::assertSame('available', $this->calls[3][4]['item_unavailabilities'][0]['status']);
    }

    public function testTheSiteClosedOpenedAndItsHours(): void
    {
        $platform = $this->platform();
        self::assertSame(StoreState::CLOSED, $platform->status()->state, 'READY_TO_OPEN: outside its hours');
        $platform->pause(new \DateTimeImmutable('+1 hour'), 'Rush');
        $platform->resume();
        $platform->setHours(new Hours([1 => [['12:00', '14:00'], ['19:00', '22:00']]]));

        self::assertSame(['PUT', 'api.developers.deliveroo.com/site/v1/brands/brand-1/sites/site-1/status', ['status' => 'CLOSED']], [$this->calls[2][0], $this->calls[2][1], $this->calls[2][4]]);
        self::assertSame(['status' => 'OPEN'], $this->calls[3][4]);
        self::assertSame('api.developers.deliveroo.com/site/v1/brands/brand-1/sites/site-1/opening_hours', $this->calls[4][1]);
        self::assertSame([['local_start_time' => '12:00', 'local_end_time' => '14:00'], ['local_start_time' => '19:00', 'local_end_time' => '22:00']], $this->calls[4][4]['opening_hours']['monday']);
        self::assertSame([], $this->calls[4][4]['opening_hours']['sunday']);
    }

    public function testTheSandbox(): void
    {
        $this->platform(['sandbox' => true])->order('gb:0a1b');

        self::assertSame('auth-sandbox.developers.deliveroo.com/oauth2/token', $this->calls[0][1]);
        self::assertStringStartsWith('api-sandbox.developers.deliveroo.com/', $this->calls[1][1]);
    }

    /** A 2xx whose body is not JSON (a proxy's page) is not an empty answer. */
    public function testAnAnswerThatIsNotJsonIsAnError(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('[deliveroo] An answer that is not JSON (HTTP 200).');
        $this->platform([], static fn () => new MockResponse('<html><body>502 Bad Gateway</body></html>', ['http_code' => 200, 'response_headers' => ['Content-Type: text/html']]))->order('gb:0a1b');
    }

    public function testErrorsAreMapped(): void
    {
        try {
            $this->platform([], static fn () => self::json(['error' => ['code' => 'invalid_status_transition', 'message' => 'Order already accepted']], 409))->accept('gb:1');
            self::fail('Accepted twice.');
        } catch (ProviderException $e) {
            self::assertSame(['invalid_status_transition', 409, '[deliveroo] Order already accepted'], [$e->providerCode, $e->status, $e->getMessage()]);
        }
        try {
            $this->platform([], static fn () => self::json(['error' => ['code' => 'scope', 'message' => 'Not allowed']], 403))->resume();
            self::fail('Not allowed.');
        } catch (UnauthorizedException $e) {
            self::assertSame('scope', $e->providerCode);
        }
    }

    public function testWithoutKeysOnlyWhatNeedsNoneWorks(): void
    {
        $platform = (new DeliverooPlatformFactory(new MockHttpClient()))->create();

        self::assertSame(600, $platform->capabilities()->acceptanceDelay);
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "deliveroo" platform needs: client_id, client_secret.');
        $platform->order('gb:1');
    }
}
