<?php

declare(strict_types=1);

namespace Varizet\PrestaShop\Tests\Unit;

use Varizet\PrestaShop\Gateway;
use Varizet\PrestaShop\Repository;
use Varizet\PrestaShop\Settings;
use Varizet\PrestaShop\Tests\Support\FakeHttp;
use Varizet\PrestaShop\Tests\Support\FakeShop;
use Varizet\PrestaShop\Tests\Support\SqliteDb;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const SECRET_TEST = 'whsec_ocTest00000000000000000000000';
    public const SECRET_LIVE = 'whsec_ocLive00000000000000000000000';

    protected FakeHttp $http;
    protected FakeShop $shop;
    protected SqliteDb $db;
    protected Repository $records;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        Settings::$transport = $this->http;
        $this->shop = new FakeShop();
        $this->db = new SqliteDb();
        $this->records = new Repository($this->db, 'ps_');
    }

    protected function tearDown(): void
    {
        Settings::$transport = null;
    }

    /** @return array<string, mixed> */
    protected static function config(array $overrides = []): array
    {
        $values = $overrides + [
            'status' => '1', 'mode' => 'test', 'api_key_test' => 'vz_test_prestaShopKey01', 'api_key_live' => 'vz_live_prestaShopKey01',
            'webhook_secret_test' => self::SECRET_TEST, 'webhook_secret_live' => self::SECRET_LIVE, 'currency_unit' => 'auto',
            'expires_in' => '1800', 'order_status_pending' => '1', 'order_status_paid' => '2', 'order_status_review' => '21',
            'order_status_expired' => '14', 'order_status_canceled' => '7', 'order_status_refunded' => '11',
        ];
        $out = [];
        foreach ($values as $k => $v) {
            $out[Settings::PREFIX . $k] = $v;
        }
        return $out;
    }

    protected function settings(array $overrides = []): Settings
    {
        $config = self::config($overrides);
        return new Settings(static fn (string $k): mixed => $config[$k] ?? null, '8.2.1');
    }

    protected function gateway(array $overrides = []): Gateway
    {
        return new Gateway($this->settings($overrides), $this->records, $this->shop);
    }

    /** @return array<string, mixed> */
    protected static function order(array $overrides = []): array
    {
        return array_replace([
            'id' => 'ord_01JBX4Q8M6', 'code' => 'VZ-8K2Q', 'status' => 'awaiting_payment', 'amount' => 8500000,
            'unique_amount' => 8500430, 'unique_mode' => 'increase', 'currency' => 'IRR',
            'payment_url' => 'https://pay.varizet.ir/p/kJ8s2QaX7mZr4TnB',
            'destination' => ['card_id' => 'card_01J', 'bank' => 'mellat', 'last4' => '1234', 'owner_name' => 'سارا'],
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 1800), 'grace_until' => gmdate('Y-m-d\TH:i:s\Z', time() + 5400),
            'paid_at' => null, 'paid_amount' => null, 'paid_late' => false, 'payer' => null, 'match' => null,
            'merchant_order_id' => 'PS-17', 'customer' => null, 'metadata' => ['platform' => 'prestashop', 'order_id' => '17'],
            'livemode' => false, 'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], $overrides);
    }
}
