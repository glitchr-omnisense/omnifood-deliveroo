<?php

namespace Omnifood\Deliveroo\Tests;

use Omnifood\Exception\RateLimitedException;
use Omnifood\Exception\UsageLimitReachedException;
use Omnifood\Deliveroo\DeliverooPlatform;
use Omnifood\Deliveroo\DeliverooPlatformFactory;
use Omnifood\Registry;
use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Deliveroo's usage policy, declared as data (contract-only, a menu upload a minute per site), and a 429 to
 * an order's acceptance: sent once, surfaced, the next calls held unsent for the wait.
 */
final class DeliverooPolicyTest extends TestCase
{
    /** @var list<array{string, string}> method and URL of each call but the token's */
    private array $calls = [];

    /** @var list<MockResponse> */
    private array $answers = [];

    public function testThePolicyIsDeclaredAsData(): void
    {
        $declared = (new PolicyExport())->folders(\dirname(__DIR__))->entries();
        self::assertCount(1, $declared, 'composer.json\'s extra.omnisense.policy names it');
        self::assertSame(['omnifood/deliveroo', 'policy.json'], [$declared[0]['package'], $declared[0]['file']]);
        self::assertArrayNotHasKey('error', $declared[0], 'it reads as the schema says');
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('deliveroo', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertSame('contract', $policy->rights->commercial->value, 'contract-only');
        self::assertSame(1, $policy->limitsFor('pushMenu')[0]->max);
        self::assertSame([], $policy->limitsFor('accept'), 'per order: in the notes, not counted');
        $registry = new Registry([new DeliverooPlatformFactory($this->http())], ['deliveroo' => ['factory' => 'deliveroo', 'options' => ['client_id' => 'cid', 'client_secret' => 'secret', 'brand_id' => 'brand-1', 'site_id' => 'site-1', 'menu_id' => 'menu-1']]]);
        self::assertEquals($policy, $registry->policy('deliveroo'));
        self::assertSame(1, $registry->guard('deliveroo')->remaining('pushMenu'));
    }

    public function testA429ToAWriteIsSurfacedNeverRetriedAndHoldsTheNextCalls(): void
    {
        $this->answers = [new MockResponse('{"error": {"code": "too_many_requests", "message": "Rate limited"}}', ['http_code' => 429, 'response_headers' => ['retry-after' => '60']])];
        $platform = (new DeliverooPlatformFactory($this->http()))->create(['client_id' => 'cid', 'client_secret' => 'secret', 'brand_id' => 'brand-1', 'site_id' => 'site-1', 'menu_id' => 'menu-1']);
        self::assertInstanceOf(DeliverooPlatform::class, $platform);

        try {
            $platform->accept('gb:1');
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertSame(60, $e->retryAfter);
            self::assertSame(429, $e->status);
            self::assertSame('deliveroo', $e->platform);
        }
        self::assertCount(1, $this->calls, 'sent once, not retried');

        $this->expectException(UsageLimitReachedException::class);
        try {
            $platform->accept('gb:1');
        } finally {
            self::assertCount(1, $this->calls, 'held, not sent');
        }
    }

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/oauth2/token')) {
                return new MockResponse('{"access_token": "recorded", "expires_in": 86400, "token_type": "Bearer"}');
            }
            $this->calls[] = [$method, $url];

            return array_shift($this->answers) ?? new MockResponse('{}');
        });
    }
}
