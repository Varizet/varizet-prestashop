<?php

declare(strict_types=1);

namespace Varizet\PrestaShop\Tests\Support;

use Varizet\PrestaShop\Db;

/** The module's SQL against in-memory SQLite (it accepts MySQL's backticks). */
final class SqliteDb implements Db
{
    public \PDO $pdo;

    public function __construct()
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec('CREATE TABLE `ps_varizet_order` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT, `order_id` INTEGER NOT NULL, `attempt` INTEGER NOT NULL,
            `varizet_order_id` TEXT NULL UNIQUE, `code` TEXT NULL, `status` TEXT NOT NULL, `amount_rial` INTEGER NOT NULL,
            `unique_amount` INTEGER NULL, `payment_url` TEXT NULL, `expires_at` TEXT NULL, `grace_until` TEXT NULL,
            `livemode` INTEGER NOT NULL DEFAULT 0, `last_event_id` TEXT NULL, `last_event_at` TEXT NULL,
            `date_added` TEXT NOT NULL, `date_modified` TEXT NOT NULL, UNIQUE (`order_id`, `attempt`))');
    }

    public function rows(string $sql): array
    {
        return array_values($this->pdo->query($sql)->fetchAll());
    }

    public function execute(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function escape(string $value): string
    {
        return substr($this->pdo->quote($value), 1, -1);
    }
}
