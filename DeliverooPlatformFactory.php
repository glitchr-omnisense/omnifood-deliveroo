<?php

namespace Omnifood\Deliveroo;

use Omnifood\Config;
use Omnifood\PlatformFactory;
use Omnifood\PlatformInterface;

/**
 * Deliveroo, through its Partner Platform (Order, Menu and Site APIs):
 *
 *   options:
 *     client_id: '%env(default::DELIVEROO_CLIENT_ID)%'          # the Developer Portal application's
 *     client_secret: '%env(default::DELIVEROO_CLIENT_SECRET)%'
 *     brand_id: '%env(default::DELIVEROO_BRAND_ID)%'            # given by Deliveroo's integration team
 *     site_id: '%env(default::DELIVEROO_SITE_ID)%'              # the restaurant's location id, the partner's own
 *     menu_id: '%env(default::DELIVEROO_MENU_ID)%'              # the menu pushMenu() writes and setAvailability() acts on
 *     webhook_secret: '%env(default::DELIVEROO_WEBHOOK_SECRET)%' # given by the account manager once the webhooks are set
 *     sandbox: false                                            # api-sandbox / auth-sandbox
 *     tablet: false                                             # the site has a Deliveroo tablet: accept()/deny() report the till's sync status
 *     language: fr                                              # the language of the menu's texts
 *     mealtime_image: ~                                         # the menu's picture (Deliveroo requires one), else the first item's
 *     base_uri: ~                                               # instead of the production's or the sandbox's
 *     token_url: ~
 */
final class DeliverooPlatformFactory extends PlatformFactory
{
    protected function populate(Config $config): void
    {
        $config->defaults([
            'omnifood.factory_name' => 'deliveroo',
            'omnifood.required_options' => [],
            'client_id' => null,
            'client_secret' => null,
            'brand_id' => null,
            'site_id' => null,
            'menu_id' => null,
            'webhook_secret' => null,
            'sandbox' => false,
            'tablet' => false,
            'language' => 'fr',
            'mealtime_image' => null,
            'base_uri' => null,
            'token_url' => null,
        ]);
    }

    protected function build(Config $config): PlatformInterface
    {
        $sandbox = filter_var($config['sandbox'], \FILTER_VALIDATE_BOOL);
        $str = static fn (string $key) => $config[$key] ? (string) $config[$key] : null;

        return new DeliverooPlatform(
            new Api($str('client_id'), $str('client_secret'), $str('base_uri') ?? ($sandbox ? Api::SANDBOX_BASE_URI : Api::BASE_URI), $str('token_url') ?? ($sandbox ? Api::SANDBOX_TOKEN_URL : Api::TOKEN_URL), $this->http),
            $str('brand_id'),
            $str('site_id'),
            $str('menu_id'),
            $str('webhook_secret'),
            filter_var($config['tablet'], \FILTER_VALIDATE_BOOL),
            (string) $config['language'],
            $str('mealtime_image'),
        );
    }
}
