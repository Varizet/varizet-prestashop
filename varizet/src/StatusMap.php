<?php

declare(strict_types=1);

namespace Varizet\PrestaShop;

use Varizet\Model\OrderStatus;

/**
 * Varizet status → PrestaShop order history (docs/10 §1.4 adapted; status ids from the settings):
 *
 * | Varizet                  | PrestaShop                                                      |
 * |--------------------------|---------------------------------------------------------------|
 * | awaiting_payment         | pending (only to undo our own review status)                  |
 * | needs_review / underpaid | review status + comment                                       |
 * | paid                     | paid status (customer notified)                               |
 * | paid after expiry/cancel | paid status + warning comment (PrestaShop takes stock again)    |
 * | expired / canceled       | expired / canceled status (PrestaShop restores stock)           |
 * | refunded                 | refunded status when it was paid; else comment                |
 * | partially_refunded       | comment                                                       |
 *
 * Statuses a human set (anything outside ours) are never overwritten by a non-payment event. Pure.
 */
final class StatusMap
{
    public const NONE = 'none';
    public const HISTORY = 'history';
    public const NOTE = 'note';

    /**
     * @param array{pending: int, paid: int, review: int, expired: int, canceled: int, refunded: int} $ids
     * @return array{action: string, status_id: ?int, note: ?string, notify: bool}
     */
    public static function decide(string $varizetStatus, int $current, array $ids): array
    {
        $unpaid = in_array($current, [0, $ids['pending'], $ids['review']], true);
        $closed = in_array($current, [$ids['expired'], $ids['canceled']], true);

        switch ($varizetStatus) {
            case OrderStatus::PAID:
                if ($current === $ids['paid']) {
                    return self::none();
                }
                if ($unpaid) {
                    return self::history($ids['paid'], 'note_paid', true);
                }
                return $closed ? self::history($ids['paid'], 'note_paid_after_cancel', true) : self::none();

            case OrderStatus::NEEDS_REVIEW:
            case OrderStatus::UNDERPAID:
                $note = $varizetStatus === OrderStatus::UNDERPAID ? 'note_underpaid' : 'note_needs_review';
                if ($unpaid) {
                    return self::history($ids['review'], $note, false);
                }
                return $closed ? self::history($ids['review'], $note . '_after_cancel', false) : self::note($note);

            case OrderStatus::AWAITING_PAYMENT:
                return $current === $ids['review'] && $ids['review'] !== $ids['pending']
                    ? self::history($ids['pending'], null, false)
                    : self::none();

            case OrderStatus::EXPIRED:
                return $unpaid ? self::history($ids['expired'], 'note_expired', true) : self::none();

            case OrderStatus::CANCELED:
                return $unpaid ? self::history($ids['canceled'], 'note_canceled', true) : self::none();

            case OrderStatus::REFUNDED:
                return $current === $ids['paid'] ? self::history($ids['refunded'], 'note_refunded', false) : self::note('note_refunded');

            case OrderStatus::PARTIALLY_REFUNDED:
                return self::note('note_partially_refunded');
        }
        return self::none();
    }

    /** @return array{action: string, status_id: ?int, note: ?string, notify: bool} */
    private static function history(int $statusId, ?string $note, bool $notify): array
    {
        return ['action' => self::HISTORY, 'status_id' => $statusId, 'note' => $note, 'notify' => $notify];
    }

    /** @return array{action: string, status_id: ?int, note: ?string, notify: bool} */
    private static function note(string $note): array
    {
        return ['action' => self::NOTE, 'status_id' => null, 'note' => $note, 'notify' => false];
    }

    /** @return array{action: string, status_id: ?int, note: ?string, notify: bool} */
    private static function none(): array
    {
        return ['action' => self::NONE, 'status_id' => null, 'note' => null, 'notify' => false];
    }
}
