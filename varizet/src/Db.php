<?php

declare(strict_types=1);

namespace Varizet\PrestaShop;

/** SQL access the repository needs (PrestaShop's `Db` in production, SQLite in the tests). */
interface Db
{
    /** @return list<array<string, mixed>> */
    public function rows(string $sql): array;

    public function execute(string $sql): void;

    public function escape(string $value): string;
}
