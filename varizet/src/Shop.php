<?php

declare(strict_types=1);

namespace Varizet\PrestaShop;

/** The PrestaShop calls the gateway needs (implemented in varizet.php with closures). */
interface Shop
{
    /**
     * @return array{order_id: int, order_status_id: int, total: string, currency_code: string, firstname: string, lastname: string, telephone: string, email: string, store_url: string}|null
     *         `total` is already in the order's currency.
     */
    public function order(int $orderId): ?array;

    /** Order state change (OrderHistory, stock and e-mails) plus a private order message. */
    public function addHistory(int $orderId, int $statusId, string $comment, bool $notify): void;

    public function returnUrl(int $orderId): string;

    /** Text for a key of lang/<iso>.php (Persian for fa shops, else English). */
    public function text(string $key): string;

    public function log(string $message): void;
}
