<?php

// Simulates the customer's transfer for a PrestaShop order through the module's own SDK client:
//   php /tmp/simulate.php <id_order>
require '/var/www/html/config/config.inc.php';

$orderId = (int) ($argv[1] ?? 0);
$module = Module::getInstanceByName('varizet');
$record = (new \Varizet\PrestaShop\Repository(new \Varizet\PrestaShop\PrestaShopDb(), _DB_PREFIX_))->latestForOrder($orderId);
if ($record === null || $record->varizetOrderId === null) {
    exit("no Varizet order for $orderId\n");
}
$client = $module->settings()->client(false);
$order = $client->orders->retrieve($record->varizetOrderId);
$deposit = $client->testHelpers->simulateDeposit(['amount' => $order->uniqueAmount, 'card_id' => (string) $order->destination?->cardId]);
echo json_encode(['order' => $order->code, 'unique_amount' => $order->uniqueAmount, 'deposit' => $deposit->id, 'match_status' => $deposit->matchStatus]), "\n";
