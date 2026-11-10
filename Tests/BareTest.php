<?php

namespace Omnifood\Deliveroo\Tests;

use Omnifood\Deliveroo\DeliverooPlatformFactory;
use Omnifood\Testing\BareTestCase;

/** omnifood/deliveroo's classes load with omnisense/omnifood, omnisense/contracts and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return DeliverooPlatformFactory::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts', 'symfony/deprecation-contracts', 'psr/container', 'symfony/polyfill-mbstring'];
    }
}
