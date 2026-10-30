<?php

declare(strict_types=1);

namespace Varizet\PrestaShop\Tests\Unit;

use Varizet\Connect\Pkce;
use Varizet\PrestaShop\OneClick;
use Varizet\PrestaShop\Settings;

final class OneClickTest extends TestCase
{
    private const ADMIN_CB = 'https://shop.ir/admin3k9/index.php?controller=AdminModules&configure=varizet&varizet_connect=callback&token=t';

    private function oneClick(array $settings = [], string $path = OneClick::DEFAULT_WEBHOOK_PATH): OneClick
    {
        $values = [];
        foreach ($settings as $k => $v) {
            $values[Settings::PREFIX . $k] = $v;
        }
        return new OneClick(new Settings(static fn (string $k): mixed => $values[$k] ?? null), 'https://shop.ir/', $path, 'https://app.varizet.test');
    }

    private static function token(string $mode): array
    {
        return [
            'api_key' => 'vz_' . $mode . '_' . str_repeat('k', 32),
            'webhook_endpoint_id' => 'whe_01K8F3M2Q0V7E6W5X4Y3Z2A1B0',
            'webhook_secret' => 'whsec_' . str_repeat('s', 32),
            'integration_id' => 'int_01K8F3M2Q0V7E6W5X4Y3Z2A1B0',
            'store' => ['id' => 'sto_01K8F3M2Q0V7E6W5X4Y3Z2A1B0', 'name' => 'فروشگاه نمونه', 'slug' => 's'],
            'livemode' => $mode === 'live',
            'scopes' => ['orders:read', 'orders:write'],
        ];
    }

    public function testStartReportsTheWebhookPathAndKeepsTheFlow(): void
    {
        $session = [];
        $url = $this->oneClick()->start(self::ADMIN_CB, $session, null, 1000);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        self::assertSame('prestashop', $q['client']);
        self::assertSame('https://shop.ir', $q['site']);
        self::assertSame(self::ADMIN_CB, $q['redirect_uri']);
        self::assertSame(OneClick::DEFAULT_WEBHOOK_PATH, $q['webhook_path']);
        self::assertSame(Settings::VERSION, $q['plugin_version']);
        self::assertSame(Pkce::challenge($session[OneClick::SESSION_KEY]['verifier']), $q['code_challenge']);

        $session = [];
        parse_str((string) parse_url($this->oneClick([], '/fa/module/varizet/webhook')->start(self::ADMIN_CB, $session, null, 1000), PHP_URL_QUERY), $q);
        self::assertSame('/fa/module/varizet/webhook', $q['webhook_path']);
    }

    public function testFinishReturnsTheSettingsOfTheConnectedMode(): void
    {
        $session = [];
        $oneClick = $this->oneClick();
        $oneClick->start(self::ADMIN_CB, $session, null, 1000);
        $this->http->json(200, self::token('live'));
        $out = $oneClick->finish(['code' => 'vzc_1', 'state' => $session[OneClick::SESSION_KEY]['state']], $session, 1001);
        self::assertTrue($out['ok']);
        self::assertSame([
            'payment_varizet_api_key_live' => 'vz_live_' . str_repeat('k', 32),
            'payment_varizet_webhook_secret_live' => 'whsec_' . str_repeat('s', 32),
            'payment_varizet_mode' => 'live',
        ], $out['values']);
        self::assertSame('فروشگاه نمونه', $out['store']);
        self::assertSame('https://shop.ir', $this->http->body(0)['site_url']);
        self::assertArrayNotHasKey(OneClick::SESSION_KEY, $session);

        // an existing key keeps the chosen mode
        $session = [];
        $configured = $this->oneClick(['mode' => 'test', 'api_key_test' => 'vz_test_existing0001']);
        $configured->start(self::ADMIN_CB, $session, null, 1000);
        $this->http->json(200, self::token('live'));
        $out = $configured->finish(['code' => 'vzc_2', 'state' => $session[OneClick::SESSION_KEY]['state']], $session, 1001);
        self::assertArrayNotHasKey('payment_varizet_mode', $out['values']);
    }

    public function testCancelledExpiredAndRefusedCodesSaveNothing(): void
    {
        $session = [];
        $oneClick = $this->oneClick();
        $oneClick->start(self::ADMIN_CB, $session, null, 1000);
        $state = $session[OneClick::SESSION_KEY]['state'];
        self::assertSame(['ok' => false, 'error' => 'error_connect_cancelled'], $oneClick->finish(['error' => 'access_denied', 'state' => $state], $session, 1001));
        self::assertSame(['ok' => false, 'error' => 'error_connect_expired'], $oneClick->finish(['code' => 'c', 'state' => $state], $session, 1001));
        $oneClick->start(self::ADMIN_CB, $session, null, 1000);
        $this->http->json(400, ['error' => ['type' => 'invalid_request', 'code' => 'invalid_grant', 'message' => 'x', 'param' => 'code', 'request_id' => 'req_1']]);
        self::assertSame(
            ['ok' => false, 'error' => 'error_connect_exchange'],
            $oneClick->finish(['code' => 'vzc_bad', 'state' => $session[OneClick::SESSION_KEY]['state']], $session, 1001),
        );
    }

    public function testWebhookPathFollowsTheModuleLinkWhenVarizetAcceptsIt(): void
    {
        self::assertSame('/module/varizet/webhook', OneClick::webhookPathOf('https://shop.ir/module/varizet/webhook', 'https://shop.ir/'));
        self::assertSame('/fa/module/varizet/webhook', OneClick::webhookPathOf('https://shop.ir/fa/module/varizet/webhook', 'https://shop.ir'));
        self::assertSame('/fa/module/varizet/webhook', OneClick::webhookPathOf('https://host.ir/shop/fa/module/varizet/webhook', 'https://host.ir/shop/'));
        // other forms (id_lang parameters, another host) fall back to the query form
        self::assertSame(OneClick::DEFAULT_WEBHOOK_PATH, OneClick::webhookPathOf('https://shop.ir/index.php?fc=module&module=varizet&controller=webhook&id_lang=1', 'https://shop.ir'));
        self::assertSame(OneClick::DEFAULT_WEBHOOK_PATH, OneClick::webhookPathOf('https://cdn.ir/module/varizet/webhook', 'https://shop.ir'));
    }
}
