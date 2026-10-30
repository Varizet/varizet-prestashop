<?php

// Smoke setup inside the container, after `php bin/console prestashop:module install varizet`
// (a plain CLI bootstrap has no Symfony container, which module install needs): make IRR the shop
// currency and configure Varizet from the environment (VARIZET_API_KEY is passed by name).
require '/var/www/html/config/config.inc.php';

$module = Module::getInstanceByName('varizet');
if (!Module::isInstalled('varizet')) {
    exit("install the module first: php bin/console prestashop:module install varizet\n");
}
$irr = (int) Currency::getIdByIsoCode('IRR');
if ($irr === 0) {
    exit("no IRR currency (PS_COUNTRY=ir should install it)\n");
}
Configuration::updateValue('PS_CURRENCY_DEFAULT', $irr);
Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'module_currency` (id_module, id_shop, id_currency) VALUES (' . (int) $module->id . ', 1, ' . $irr . ')');
Configuration::updateValue('VARIZET_MODE', 'test');
Configuration::updateValue('VARIZET_API_KEY_TEST', (string) getenv('VARIZET_API_KEY'));
Configuration::updateValue('VARIZET_WEBHOOK_SECRET_TEST', (string) getenv('VARIZET_WEBHOOK_SECRET'));
Configuration::updateValue('VARIZET_API_BASE_URL', (string) getenv('VARIZET_API_BASE_URL'));
Configuration::updateValue('VARIZET_CURRENCY_UNIT', 'auto');
echo json_encode([
    'module_id' => (int) $module->id,
    'currency' => 'IRR',
    'awaiting_state' => (int) Configuration::get('VARIZET_OS_AWAITING'),
    'cron_token' => Configuration::get('VARIZET_CRON_TOKEN'),
]), "\n";
