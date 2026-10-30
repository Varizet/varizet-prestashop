<?php

declare(strict_types=1);

namespace Varizet\PrestaShop;

/** `Shop` from closures, so the module class keeps the PrestaShop glue in one place. */
final class CallbackShop implements Shop
{
    public function __construct(
        private readonly \Closure $order,
        private readonly \Closure $addHistory,
        private readonly \Closure $returnUrl,
        private readonly \Closure $text,
        private readonly \Closure $log,
    ) {
    }

    public function order(int $orderId): ?array
    {
        return ($this->order)($orderId);
    }

    public function addHistory(int $orderId, int $statusId, string $comment, bool $notify): void
    {
        ($this->addHistory)($orderId, $statusId, $comment, $notify);
    }

    public function returnUrl(int $orderId): string
    {
        return ($this->returnUrl)($orderId);
    }

    public function text(string $key): string
    {
        return ($this->text)($key);
    }

    public function log(string $message): void
    {
        ($this->log)($message);
    }
}
