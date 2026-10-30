<?php

declare(strict_types=1);

namespace Varizet\PrestaShop;

/** PrestaShop's `Db::getInstance()` and `pSQL()`. */
final class PrestaShopDb implements Db
{
    public function rows(string $sql): array
    {
        $rows = \Db::getInstance()->executeS($sql);
        return is_array($rows) ? array_values($rows) : [];
    }

    public function execute(string $sql): void
    {
        \Db::getInstance()->execute($sql);
    }

    public function escape(string $value): string
    {
        return \pSQL($value);
    }
}
