<?php

declare(strict_types=1);

namespace Varizet\PrestaShop;

/** One Varizet order created for an PrestaShop order (row of `{prefix}varizet_order`). */
final class Record
{
    public const CREATING = 'creating';

    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly int $attempt,
        public readonly ?string $varizetOrderId,
        public readonly ?string $code,
        public readonly string $status,
        public readonly int $amountRial,
        public readonly ?int $uniqueAmount,
        public readonly ?string $paymentUrl,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $graceUntil,
        public readonly bool $livemode,
        public readonly ?string $lastEventId,
        public readonly ?\DateTimeImmutable $lastEventAt,
    ) {
    }
}
