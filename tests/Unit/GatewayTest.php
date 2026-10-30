<?php

declare(strict_types=1);

namespace Varizet\PrestaShop\Tests\Unit;

use Varizet\PrestaShop\Record;
use Varizet\PrestaShop\Settings;
use Varizet\Webhook\Signature;

final class GatewayTest extends TestCase
{
    public function testStartCreatesTheOrderConfirmsItAndRedirects(): void
    {
        $this->shop->addOrder(17, 0, '850000.0000', 'IRT');
        $this->http->json(201, self::order());
        $result = $this->gateway()->start(17);

        self::assertSame(['redirect' => 'https://pay.varizet.ir/p/kJ8s2QaX7mZr4TnB'], $result);
        $req = $this->http->requests[0];
        $site = $this->settings()->siteFingerprint('https://shop.example.ir/');
        self::assertSame('prestashop-17-1-' . $site, $req->headers['Idempotency-Key']);
        self::assertMatchesRegularExpression('#^prestashop/' . preg_quote(Settings::VERSION, '#') . '; ps=8\.2\.1; sdk=#', $req->headers['Varizet-Plugin']);
        self::assertSame([
            'amount' => 8_500_000,
            'merchant_order_id' => 'PS-17',
            'description' => 'سفارش #17',
            'expires_in' => 1800,
            'metadata' => ['platform' => 'prestashop', 'order_id' => '17', 'site' => $site],
            'customer' => ['name' => 'سارا محمدی', 'phone' => '09121234567', 'email' => 'sara@example.ir'],
            'return_url' => 'https://shop.example.ir/module/varizet/return?key=k&id_order=17',
        ], $this->http->body(0));
        // The order is confirmed as pending with the unique amount in the history.
        self::assertSame(17, $this->shop->history[0][0]);
        self::assertSame(1, $this->shop->history[0][1]);
        self::assertStringContainsString('VZ-8K2Q', $this->shop->history[0][2]);
        self::assertStringContainsString('۸٬۵۰۰٬۴۳۰', $this->shop->history[0][2]);
        self::assertFalse($this->shop->history[0][3]);

        // Clicking again reuses the open order.
        self::assertSame($result, $this->gateway()->start(17));
        self::assertCount(1, $this->http->requests);
        self::assertCount(1, $this->shop->history);
    }

    public function testStartRefusals(): void
    {
        $this->shop->addOrder(1, 2);
        self::assertSame(['error' => 'این سفارش در وضعیت قابل پرداخت نیست.'], $this->gateway()->start(1));
        $this->shop->addOrder(2, 0, '10000', 'EUR');
        self::assertStringContainsString('«EUR»', $this->gateway()->start(2)['error']);
        $this->shop->addOrder(3, 0, '999', 'IRT');
        self::assertStringContainsString('۱۰٬۰۰۰', $this->gateway()->start(3)['error']);
        self::assertStringContainsString('پیدا نشد', $this->gateway()->start(99)['error']);
        self::assertStringContainsString('تنظیم نشده', $this->gateway(['api_key_test' => ''])->start(3)['error']);
        // A store unit override makes any code usable.
        $this->http->json(201, self::order(['amount' => 100000]));
        self::assertArrayHasKey('redirect', $this->gateway(['currency_unit' => 'IRT'])->start(2));
        self::assertSame(100_000, $this->http->body(0)['amount']);
        self::assertCount(1, $this->http->requests);
    }

    public function testApiErrorsAndAttempts(): void
    {
        $this->shop->addOrder(17);
        $this->http->json(409, ['error' => ['type' => 'conflict', 'code' => 'idempotency_mismatch', 'message' => 'm', 'param' => null, 'request_id' => 'r']])
            ->json(201, self::order());
        self::assertArrayHasKey('redirect', $this->gateway()->start(17));
        self::assertSame('PS-17-2', $this->http->body(1)['merchant_order_id']);

        $this->shop->addOrder(18);
        $this->http->json(409, ['error' => ['type' => 'conflict', 'code' => 'no_active_card', 'message' => 'هیچ کارت فعالی برای دریافت وجه وجود ندارد.', 'param' => null, 'request_id' => 'r']]);
        self::assertSame(['error' => 'هیچ کارت فعالی برای دریافت وجه وجود ندارد.'], $this->gateway()->start(18));
        self::assertStringContainsString('no_active_card', $this->shop->logs[0]);
    }

    private function deliver(string $type, array $object, string $secret = self::SECRET_TEST, bool $live = false, string $id = 'evt_01JBX5ABCDEFGH'): array
    {
        $body = (string) json_encode(['id' => $id, 'type' => $type, 'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'livemode' => $live, 'data' => ['object' => $object]]);
        return $this->gateway()->webhook($body, Signature::buildHeader([$secret], time(), $body));
    }

    public function testWebhookPaysTheOrderOnce(): void
    {
        $this->shop->addOrder(17);
        $this->http->json(201, self::order());
        $this->gateway()->start(17);

        $r = $this->deliver('order.paid', self::order(['status' => 'paid', 'paid_amount' => 8500430, 'match' => ['score' => 97, 'channel' => 'android_sms', 'deposit_id' => 'dep_1', 'reasons' => []]]));
        self::assertSame(['status' => 200, 'body' => ['received' => true, 'result' => 'history']], $r);
        self::assertSame([17, 2], array_slice($this->shop->history[1], 0, 2));
        self::assertStringContainsString('VZ-8K2Q', $this->shop->history[1][2]);
        self::assertStringContainsString('امتیاز تطبیق 97', $this->shop->history[1][2]);
        self::assertTrue($this->shop->history[1][3], 'customer notified');
        self::assertSame('paid', $this->records->latestForOrder(17)?->status);

        self::assertSame('duplicate', $this->deliver('order.paid', self::order(['status' => 'paid']))['body']['result']);
        self::assertSame('stale', $this->deliver('order.expired', self::order(['status' => 'expired']), self::SECRET_TEST, false, 'evt_01JBX5LATEEXPIRE')['body']['result']);
        self::assertCount(2, $this->shop->history);
    }

    public function testWebhookRejectionsAndForeignOrders(): void
    {
        $body = '{"id":"evt_01JBX5ABCDEFGH"}';
        self::assertSame(401, $this->gateway()->webhook($body, null)['status']);
        self::assertSame(503, $this->gateway(['webhook_secret_test' => '', 'webhook_secret_live' => ''])->webhook($body, 't=1,v1=' . str_repeat('a', 64))['status']);
        self::assertSame(400, $this->gateway()->webhook($body, Signature::buildHeader([self::SECRET_TEST], time(), $body))['status']);
        self::assertSame(400, $this->deliver('order.paid', self::order(['status' => 'paid', 'livemode' => true]), self::SECRET_TEST, true)['status']);
        self::assertSame('pong', $this->deliver('webhook.ping', ['id' => 'whe_1'])['body']['result']);
        self::assertSame('unknown_order', $this->deliver('order.paid', self::order(['status' => 'paid', 'metadata' => []]))['body']['result']);
        // Ours, created seconds ago, not saved yet: Varizet should retry.
        $this->shop->addOrder(17);
        self::assertSame(409, $this->deliver('order.created', self::order(['metadata' => ['platform' => 'prestashop', 'order_id' => '17', 'site' => 'abcd1234']]))['status']);
    }

    public function testReturnSyncAndPolling(): void
    {
        $this->shop->addOrder(17);
        $this->http->json(201, self::order());
        $this->gateway()->start(17);

        $this->http->json(200, self::order(['status' => 'needs_review']));
        self::assertSame('needs_review', $this->gateway()->sync(17));
        self::assertSame(21, $this->shop->orders[17]['order_status_id']);
        self::assertNull($this->gateway()->sync(999));

        $this->shop->addOrder(18);
        $this->http->json(201, self::order(['id' => 'ord_SECOND', 'code' => 'VZ-2222']));
        $this->gateway()->start(18);

        $this->http->json(200, ['data' => [self::order(['status' => 'paid']), self::order(['id' => 'ord_SECOND', 'code' => 'VZ-2222', 'status' => 'expired'])], 'has_more' => false, 'next_cursor' => null]);
        self::assertSame(['checked' => 2, 'changed' => 2], $this->gateway()->poll(new \DateTimeImmutable()));
        self::assertSame(2, $this->shop->orders[17]['order_status_id']);
        self::assertSame(14, $this->shop->orders[18]['order_status_id']);
        self::assertStringContainsString('id%5B%5D=ord_01JBX4Q8M6&id%5B%5D=ord_SECOND', end($this->http->requests)->url);

        // Nothing open any more: no request at all.
        $before = count($this->http->requests);
        self::assertSame(['checked' => 0, 'changed' => 0], $this->gateway()->poll(new \DateTimeImmutable()));
        self::assertCount($before, $this->http->requests);
    }

    public function testRepositoryReservationsReplayAfterACrash(): void
    {
        $this->shop->addOrder(17);
        $this->records->reserve(17, 1, 8_500_000, false);
        self::assertSame(Record::CREATING, $this->records->latestForOrder(17)?->status);
        $this->http->json(201, self::order());
        $this->gateway()->start(17);
        self::assertStringStartsWith('prestashop-17-1-', $this->http->requests[0]->headers['Idempotency-Key']);
        self::assertCount(1, $this->db->rows('SELECT * FROM `ps_varizet_order`'));
    }
}
