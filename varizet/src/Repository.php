<?php

declare(strict_types=1);

namespace Varizet\PrestaShop;

use Varizet\Model\Order;
use Varizet\Model\OrderStatus;

/** `{DB_PREFIX}varizet_order` with plain SQL (MySQL in PrestaShop, SQLite in the unit tests). */
final class Repository
{
    private string $table;

    public function __construct(private readonly Db $db, string $prefix)
    {
        $this->table = $prefix . 'varizet_order';
    }

    /** @return list<string> MySQL DDL run by the admin extension's install(). */
    public static function installSql(string $prefix): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS `' . $prefix . 'varizet_order` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` INT UNSIGNED NOT NULL,
                `attempt` INT UNSIGNED NOT NULL,
                `varizet_order_id` VARCHAR(64) NULL,
                `code` VARCHAR(32) NULL,
                `status` VARCHAR(32) NOT NULL,
                `amount_rial` BIGINT UNSIGNED NOT NULL,
                `unique_amount` BIGINT UNSIGNED NULL,
                `payment_url` VARCHAR(512) NULL,
                `expires_at` DATETIME NULL,
                `grace_until` DATETIME NULL,
                `livemode` TINYINT(1) NOT NULL DEFAULT 0,
                `last_event_id` VARCHAR(64) NULL,
                `last_event_at` DATETIME NULL,
                `date_added` DATETIME NOT NULL,
                `date_modified` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `order_attempt` (`order_id`, `attempt`),
                UNIQUE KEY `varizet_order_id` (`varizet_order_id`),
                KEY `status_grace` (`status`, `grace_until`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
        ];
    }

    public function latestForOrder(int $orderId): ?Record
    {
        return $this->one('SELECT * FROM `' . $this->table . '` WHERE `order_id` = ' . $orderId . ' ORDER BY `attempt` DESC LIMIT 1');
    }

    public function findByVarizetId(string $varizetOrderId): ?Record
    {
        return $this->one('SELECT * FROM `' . $this->table . "` WHERE `varizet_order_id` = '" . $this->db->escape($varizetOrderId) . "' LIMIT 1");
    }

    public function reserve(int $orderId, int $attempt, int $amountRial, bool $livemode): Record
    {
        $existing = $this->one('SELECT * FROM `' . $this->table . '` WHERE `order_id` = ' . $orderId . ' AND `attempt` = ' . $attempt);
        if ($existing !== null) {
            return $existing;
        }
        $now = self::now();
        $this->db->execute('INSERT INTO `' . $this->table . '` (`order_id`, `attempt`, `status`, `amount_rial`, `livemode`, `date_added`, `date_modified`) VALUES ('
            . $orderId . ', ' . $attempt . ", '" . Record::CREATING . "', " . $amountRial . ', ' . ($livemode ? 1 : 0) . ", '" . $now . "', '" . $now . "')");
        return $this->one('SELECT * FROM `' . $this->table . '` WHERE `order_id` = ' . $orderId . ' AND `attempt` = ' . $attempt)
            ?? throw new \RuntimeException('varizet_order insert failed');
    }

    public function saveCreated(Record $record, Order $order): Record
    {
        $this->db->execute('UPDATE `' . $this->table . '` SET '
            . "`varizet_order_id` = '" . $this->db->escape($order->id) . "', "
            . '`code` = ' . $this->quote($order->code) . ', '
            . "`status` = '" . $this->db->escape($order->status) . "', "
            . '`unique_amount` = ' . $order->uniqueAmount . ', '
            . '`payment_url` = ' . $this->quote($order->paymentUrl) . ', '
            . '`expires_at` = ' . $this->quote(self::sql($order->expiresAt)) . ', '
            . '`grace_until` = ' . $this->quote(self::sql($order->graceUntil)) . ', '
            . '`livemode` = ' . ($order->livemode ? 1 : 0) . ', '
            . "`date_modified` = '" . self::now() . "' WHERE `id` = " . $record->id);
        return $this->one('SELECT * FROM `' . $this->table . '` WHERE `id` = ' . $record->id) ?? $record;
    }

    public function updateStatus(Record $record, string $status, ?string $eventId, ?\DateTimeImmutable $eventAt): void
    {
        $sets = ["`status` = '" . $this->db->escape($status) . "'", "`date_modified` = '" . self::now() . "'"];
        if ($eventId !== null) {
            $sets[] = "`last_event_id` = '" . $this->db->escape($eventId) . "'";
        }
        if ($eventAt !== null) {
            $sets[] = "`last_event_at` = '" . self::sql($eventAt) . "'";
        }
        $this->db->execute('UPDATE `' . $this->table . '` SET ' . implode(', ', $sets) . ' WHERE `id` = ' . $record->id);
    }

    /** @return list<Record> */
    public function pollable(\DateTimeImmutable $now, int $limit): array
    {
        $open = implode(', ', array_map(static fn (string $s): string => "'" . $s . "'", OrderStatus::OPEN));
        $rows = $this->db->rows('SELECT * FROM `' . $this->table . '` WHERE `status` IN (' . $open . ')'
            . ' AND `varizet_order_id` IS NOT NULL'
            . " AND `grace_until` > '" . self::sql($now->modify('-6 hours')) . "'"
            . ' ORDER BY `date_modified` ASC LIMIT ' . max(1, $limit));
        return array_map(self::hydrate(...), $rows);
    }

    private function one(string $sql): ?Record
    {
        $rows = $this->db->rows($sql);
        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    private function quote(?string $value): string
    {
        return $value === null ? 'NULL' : "'" . $this->db->escape($value) . "'";
    }

    /** @param array<string, mixed> $r */
    private static function hydrate(array $r): Record
    {
        $date = static fn ($v): ?\DateTimeImmutable => $v === null || $v === '' ? null : new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC'));
        $str = static fn ($v): ?string => $v === null ? null : (string) $v;
        return new Record(
            (int) $r['id'],
            (int) $r['order_id'],
            (int) $r['attempt'],
            $str($r['varizet_order_id']),
            $str($r['code']),
            (string) $r['status'],
            (int) $r['amount_rial'],
            $r['unique_amount'] === null ? null : (int) $r['unique_amount'],
            $str($r['payment_url']),
            $date($r['expires_at']),
            $date($r['grace_until']),
            (bool) $r['livemode'],
            $str($r['last_event_id']),
            $date($r['last_event_at']),
        );
    }

    private static function sql(?\DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
