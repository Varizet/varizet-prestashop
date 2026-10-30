<?php

declare(strict_types=1);

namespace Varizet\PrestaShop;

use Varizet\Exception\ApiException;
use Varizet\Exception\IdempotencyException;
use Varizet\Exception\InvalidArgumentException;
use Varizet\Exception\InvalidPayloadException;
use Varizet\Exception\NetworkException;
use Varizet\Exception\RateLimitException;
use Varizet\Exception\SignatureVerificationException;
use Varizet\Exception\ValidationException;
use Varizet\Model\Order;
use Varizet\Model\OrderStatus;
use Varizet\Util\Amount;
use Varizet\Util\Format;
use Varizet\Util\IdempotencyKey;
use Varizet\Util\Money;
use Varizet\Webhook\Webhook;

/**
 * Everything the PrestaShop extensions do, behind the small `Shop` port: start a payment,
 * receive webhooks, sync on return, poll. One writer (`apply`) with the same guards as the
 * WooCommerce plugin: replayed event ids, late events, settled payments never regress.
 */
final class Gateway
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Repository $records,
        private readonly Shop $shop,
    ) {
    }

    /**
     * @return array{redirect: string}|array{error: string}
     */
    public function start(int $orderId, \DateTimeImmutable $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))): array
    {
        $client = $this->settings->client();
        if ($client === null) {
            return ['error' => $this->shop->text('error_not_configured')];
        }
        $order = $this->shop->order($orderId);
        if ($order === null) {
            return ['error' => $this->shop->text('error_order')];
        }
        $ids = $this->ids();
        if (!in_array($order['order_status_id'], [0, $ids['pending'], $ids['review']], true)) {
            return ['error' => $this->shop->text('error_not_payable')];
        }
        try {
            $rial = Amount::toRialWithFactor($order['total'], $this->settings->factorFor($order['currency_code']));
        } catch (InvalidArgumentException) {
            return ['error' => sprintf($this->shop->text('error_currency'), $order['currency_code'])];
        }
        if ($rial < Money::MIN_ORDER_RIAL) {
            return ['error' => $this->shop->text('error_min_amount')];
        }

        $live = $this->settings->livemode();
        $latest = $this->records->latestForOrder($orderId);
        if ($latest !== null && $latest->status === OrderStatus::AWAITING_PAYMENT && $latest->paymentUrl !== null
            && $latest->amountRial === $rial && $latest->livemode === $live
            && $latest->expiresAt !== null && $latest->expiresAt->getTimestamp() > $now->getTimestamp() + 120) {
            return ['redirect' => $latest->paymentUrl];
        }
        $attempt = $latest === null ? 1
            : ($latest->status === Record::CREATING && $latest->amountRial === $rial && $latest->livemode === $live ? $latest->attempt : $latest->attempt + 1);
        $site = $this->settings->siteFingerprint($order['store_url']);

        for ($try = 0; ; $try++) {
            $record = $this->records->reserve($orderId, $attempt, $rial, $live);
            try {
                $created = $client->orders->create(
                    $this->params($order, $rial, $attempt, $site),
                    IdempotencyKey::forAttempt('prestashop', $orderId, $attempt, $site),
                );
                break;
            } catch (IdempotencyException | ValidationException $e) {
                if ($try >= 2 || ($e instanceof ValidationException && $e->errorCode !== 'merchant_order_id_duplicate')) {
                    return ['error' => $e->getMessage()];
                }
                ++$attempt;
            } catch (NetworkException) {
                return ['error' => $this->shop->text('error_network')];
            } catch (ApiException $e) {
                $this->shop->log(sprintf('create order %d failed: %s (%s)', $orderId, $e->errorCode, (string) $e->requestId));
                return ['error' => $e->getMessage()];
            }
        }
        $this->records->saveCreated($record, $created);
        if ($order['order_status_id'] === 0) {
            $this->shop->addHistory($orderId, $ids['pending'], sprintf(
                $this->shop->text('note_created'),
                (string) $created->code,
                Format::number($created->uniqueAmount),
            ), false);
        }
        if ($this->settings->debug()) {
            $this->shop->log(sprintf('order %d attempt %d → %s (%s), request %s', $orderId, $attempt, $created->id, (string) $created->code, (string) $client->lastResponse()?->requestId));
        }
        return $created->paymentUrl === null ? ['error' => $this->shop->text('error_network')] : ['redirect' => $created->paymentUrl];
    }

    /**
     * @param array{order_id: int, firstname: string, lastname: string, telephone: string, email: string} $order
     * @return array<string, mixed>
     */
    public function params(array $order, int $rial, int $attempt, string $site): array
    {
        $params = [
            'amount' => $rial,
            'merchant_order_id' => 'PS-' . $order['order_id'] . ($attempt > 1 ? '-' . $attempt : ''),
            'description' => sprintf($this->shop->text('order_description'), $order['order_id']),
            'expires_in' => $this->settings->expiresIn(),
            'metadata' => ['platform' => 'prestashop', 'order_id' => (string) $order['order_id'], 'site' => $site],
        ];
        $customer = array_filter([
            'name' => mb_substr(trim($order['firstname'] . ' ' . $order['lastname']), 0, 120),
            'phone' => self::mobile($order['telephone']),
            'email' => filter_var($order['email'], FILTER_VALIDATE_EMAIL) ? $order['email'] : null,
        ], static fn ($v): bool => $v !== null && $v !== '');
        if ($customer !== []) {
            $params['customer'] = $customer;
        }
        $return = $this->shop->returnUrl($order['order_id']);
        if (str_starts_with($return, 'https://') || preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/#', $return) === 1) {
            $params['return_url'] = $return;
        }
        return $params;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function webhook(string $payload, ?string $signature, ?int $now = null): array
    {
        if (strlen($payload) > 262_144) {
            return ['status' => 413, 'body' => ['error' => 'payload_too_large']];
        }
        try {
            $event = Webhook::constructEvent($payload, $signature, $this->settings->webhookSecrets(), 300, $now);
        } catch (InvalidArgumentException) {
            return ['status' => 503, 'body' => ['error' => 'not_configured']];
        } catch (SignatureVerificationException $e) {
            $this->shop->log('webhook rejected: ' . $e->reason);
            return ['status' => 401, 'body' => ['error' => 'invalid_signature', 'reason' => $e->reason]];
        } catch (InvalidPayloadException) {
            return ['status' => 400, 'body' => ['error' => 'invalid_payload']];
        }
        if ($event->isPing()) {
            return ['status' => 200, 'body' => ['received' => true, 'result' => 'pong']];
        }
        try {
            $order = $event->order();
        } catch (InvalidPayloadException) {
            return ['status' => 400, 'body' => ['error' => 'invalid_payload']];
        }
        if ($order === null || $order->test) {
            return ['status' => 200, 'body' => ['received' => true, 'result' => 'ignored']];
        }
        $record = $this->records->findByVarizetId($order->id);
        if ($record === null) {
            $ours = ($order->metadata['platform'] ?? '') === 'prestashop'
                && $order->createdAt !== null && ($now ?? time()) - $order->createdAt->getTimestamp() < 600
                && ($order->metadata['site'] ?? '') !== ''
                && $this->shop->order((int) ($order->metadata['order_id'] ?? 0)) !== null;
            return $ours
                ? ['status' => 409, 'body' => ['error' => 'not_ready']]
                : ['status' => 200, 'body' => ['received' => true, 'result' => 'unknown_order']];
        }
        try {
            $result = $this->apply($record, $order, $event->id, $event->createdAt);
        } catch (\RuntimeException $e) {
            $this->shop->log('webhook apply failed: ' . $e->getMessage());
            return ['status' => 500, 'body' => ['error' => 'apply_failed']];
        }
        return ['status' => 200, 'body' => ['received' => true, 'result' => $result]];
    }

    /** The customer is back: read the order once and apply it. Returns the Varizet status. */
    public function sync(int $orderId): ?string
    {
        $record = $this->records->latestForOrder($orderId);
        if ($record === null || $record->varizetOrderId === null) {
            return null;
        }
        $client = $this->settings->client($record->livemode);
        if ($client === null) {
            return $record->status;
        }
        try {
            $order = $client->orders->retrieve($record->varizetOrderId);
        } catch (\Varizet\Exception\ExceptionInterface $e) {
            $this->shop->log('return sync failed: ' . $e->getMessage());
            return $record->status;
        }
        $this->apply($record, $order);
        return $order->status;
    }

    /**
     * Polling fallback: open records, batched `GET /v1/orders?id[]=` per mode.
     *
     * @return array{checked: int, changed: int}
     */
    public function poll(\DateTimeImmutable $now, int $limit = 300): array
    {
        $stats = ['checked' => 0, 'changed' => 0];
        $byMode = [];
        foreach ($this->records->pollable($now, $limit) as $record) {
            $byMode[$record->livemode ? 1 : 0][] = $record;
        }
        foreach ($byMode as $live => $records) {
            $client = $this->settings->client((bool) $live);
            if ($client === null) {
                continue;
            }
            foreach (array_chunk($records, 100) as $chunk) {
                try {
                    $orders = $client->orders->retrieveMany(array_values(array_filter(array_map(static fn (Record $r): ?string => $r->varizetOrderId, $chunk))));
                } catch (RateLimitException | NetworkException) {
                    return $stats;
                } catch (ApiException $e) {
                    $this->shop->log('poll failed: ' . $e->errorCode);
                    continue;
                }
                foreach ($chunk as $record) {
                    ++$stats['checked'];
                    $order = $orders[(string) $record->varizetOrderId] ?? null;
                    if ($order !== null && $order->status !== $record->status) {
                        $stats['changed'] += in_array($this->apply($record, $order), ['duplicate', 'stale'], true) ? 0 : 1;
                    }
                }
            }
        }
        return $stats;
    }

    /** The single writer. */
    public function apply(Record $record, Order $order, ?string $eventId = null, ?\DateTimeImmutable $eventAt = null): string
    {
        if ($eventId !== null && $eventId === $record->lastEventId) {
            return 'duplicate';
        }
        if (OrderStatus::isRegression($record->status, $order->status)) {
            return 'stale';
        }
        if ($eventAt !== null && $record->lastEventAt !== null && $eventAt < $record->lastEventAt && $order->status !== OrderStatus::PAID) {
            return 'stale';
        }
        $shopOrder = $this->shop->order($record->orderId);
        if ($shopOrder !== null) {
            $d = StatusMap::decide($order->status, $shopOrder['order_status_id'], $this->ids());
            if ($d['action'] !== StatusMap::NONE) {
                $this->shop->addHistory(
                    $record->orderId,
                    $d['status_id'] ?? $shopOrder['order_status_id'],
                    $d['note'] === null ? '' : $this->note($d['note'], $order),
                    $d['notify'],
                );
            }
            $result = $d['action'];
        } else {
            $result = 'unknown_order';
        }
        $this->records->updateStatus($record, $order->status, $eventId, $eventAt);
        return $result;
    }

    /** @return array{pending: int, paid: int, review: int, expired: int, canceled: int, refunded: int} */
    private function ids(): array
    {
        $ids = [];
        foreach (['pending', 'paid', 'review', 'expired', 'canceled', 'refunded'] as $k) {
            $ids[$k] = $this->settings->statusId($k);
        }
        /** @var array{pending: int, paid: int, review: int, expired: int, canceled: int, refunded: int} $ids */
        return $ids;
    }

    private function note(string $key, Order $order): string
    {
        $text = $this->shop->text($key);
        return $key === 'note_paid'
            ? sprintf($text, (string) $order->code, Format::number($order->paidAmount ?? $order->uniqueAmount), (string) ($order->match?->score ?? '-'))
            : sprintf($text, (string) $order->code);
    }

    /** `+98 912…`, `۰۹۱۲…` → `09121234567`; anything else is dropped. */
    public static function mobile(string $phone): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', Format::toLatinDigits($phone));
        if (str_starts_with($digits, '0098')) {
            $digits = '0' . substr($digits, 4);
        } elseif (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0' . $digits;
        }
        return preg_match('/^09\d{9}$/D', $digits) === 1 ? $digits : null;
    }
}
