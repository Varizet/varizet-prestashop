<?php

declare(strict_types=1);

namespace Varizet\PrestaShop\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Varizet\PrestaShop\StatusMap as M;

final class StatusMapTest extends TestCase
{
    private const IDS = ['pending' => 1, 'paid' => 2, 'review' => 21, 'expired' => 14, 'canceled' => 7, 'refunded' => 11];

    /** @return array<string, array{0: string, 1: int, 2: string, 3: ?int, 4: ?string, 5: bool}> */
    public static function table(): array
    {
        return [
            'paid pending' => ['paid', 1, M::HISTORY, 2, 'note_paid', true],
            'paid missing order' => ['paid', 0, M::HISTORY, 2, 'note_paid', true],
            'paid review' => ['paid', 21, M::HISTORY, 2, 'note_paid', true],
            'paid already' => ['paid', 2, M::NONE, null, null, false],
            'paid after expiry' => ['paid', 14, M::HISTORY, 2, 'note_paid_after_cancel', true],
            'paid after cancel' => ['paid', 7, M::HISTORY, 2, 'note_paid_after_cancel', true],
            'paid shipped by hand' => ['paid', 3, M::NONE, null, null, false],
            'review pending' => ['needs_review', 1, M::HISTORY, 21, 'note_needs_review', false],
            'underpaid pending' => ['underpaid', 1, M::HISTORY, 21, 'note_underpaid', false],
            'underpaid after cancel' => ['underpaid', 7, M::HISTORY, 21, 'note_underpaid_after_cancel', false],
            'review on paid' => ['needs_review', 2, M::NOTE, null, 'note_needs_review', false],
            'awaiting after review' => ['awaiting_payment', 21, M::HISTORY, 1, null, false],
            'awaiting pending' => ['awaiting_payment', 1, M::NONE, null, null, false],
            'expired pending' => ['expired', 1, M::HISTORY, 14, 'note_expired', true],
            'expired review' => ['expired', 21, M::HISTORY, 14, 'note_expired', true],
            'expired paid' => ['expired', 2, M::NONE, null, null, false],
            'canceled pending' => ['canceled', 1, M::HISTORY, 7, 'note_canceled', true],
            'canceled shipped' => ['canceled', 3, M::NONE, null, null, false],
            'refunded paid' => ['refunded', 2, M::HISTORY, 11, 'note_refunded', false],
            'refunded shipped' => ['refunded', 3, M::NOTE, null, 'note_refunded', false],
            'partial' => ['partially_refunded', 2, M::NOTE, null, 'note_partially_refunded', false],
            'unknown' => ['brand_new', 1, M::NONE, null, null, false],
        ];
    }

    #[DataProvider('table')]
    public function testDecision(string $varizet, int $current, string $action, ?int $status, ?string $note, bool $notify): void
    {
        self::assertSame(['action' => $action, 'status_id' => $status, 'note' => $note, 'notify' => $notify], M::decide($varizet, $current, self::IDS));
    }

    public function testReviewSameAsPendingDoesNotBounce(): void
    {
        $ids = ['review' => 1] + self::IDS;
        self::assertSame(M::NONE, M::decide('awaiting_payment', 1, $ids)['action']);
    }
}
