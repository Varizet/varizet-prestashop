<?php

declare(strict_types=1);

namespace Varizet\PrestaShop\Tests\Support;

use Varizet\PrestaShop\Shop;

final class FakeShop implements Shop
{
    /** @var array<int, array<string, mixed>> */
    public array $orders = [];
    /** @var list<array{0: int, 1: int, 2: string, 3: bool}> */
    public array $history = [];
    /** @var list<string> */
    public array $logs = [];
    /** @var array<string, string> */
    private array $lang;

    public function __construct()
    {
        $this->lang = require __DIR__ . '/../../varizet/lang/fa.php';
    }

    public function addOrder(int $id, int $status = 0, string $total = '850000', string $currency = 'IRT'): void
    {
        $this->orders[$id] = [
            'order_id' => $id, 'order_status_id' => $status, 'total' => $total, 'currency_code' => $currency,
            'firstname' => 'سارا', 'lastname' => 'محمدی', 'telephone' => '۰۹۱۲۱۲۳۴۵۶۷', 'email' => 'sara@example.ir',
            'store_url' => 'https://shop.example.ir/',
        ];
    }

    public function order(int $orderId): ?array
    {
        return $this->orders[$orderId] ?? null;
    }

    public function addHistory(int $orderId, int $statusId, string $comment, bool $notify): void
    {
        $this->history[] = [$orderId, $statusId, $comment, $notify];
        $this->orders[$orderId]['order_status_id'] = $statusId;
    }

    public function returnUrl(int $orderId): string
    {
        return 'https://shop.example.ir/module/varizet/return?key=k&id_order=' . $orderId;
    }

    public function text(string $key): string
    {
        return $this->lang[$key] ?? $key;
    }

    public function log(string $message): void
    {
        $this->logs[] = $message;
    }
}
