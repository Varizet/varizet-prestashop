<?php

/**
 * Varizet («واریزت») for PrestaShop 8: card-to-card payments with a unique amount and automatic
 * confirmation. Checkout → controllers/front/redirect.php (order in «در انتظار پرداخت واریزت» →
 * Varizet order → hosted payment page); signed webhooks → webhook.php; polling → cron.php.
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Varizet\PrestaShop\CallbackShop;
use Varizet\PrestaShop\Gateway;
use Varizet\PrestaShop\PrestaShopDb;
use Varizet\PrestaShop\Repository;
use Varizet\PrestaShop\Settings;
use Varizet\Util\Amount;
use Varizet\Util\Format;
use Varizet\Util\Money;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

class Varizet extends PaymentModule
{
    /** Settings stored in Configuration as VARIZET_<NAME>. */
    private const FIELDS = [
        'mode', 'api_key_live', 'api_key_test', 'webhook_secret_live', 'webhook_secret_test',
        'currency_unit', 'expires_in', 'api_base_url', 'debug',
    ];
    private const SECRET_FIELDS = ['api_key_live', 'api_key_test', 'webhook_secret_live', 'webhook_secret_test'];

    /** @var array<string, string>|null */
    private ?array $strings = null;

    public function __construct()
    {
        $this->name = 'varizet';
        $this->tab = 'payments_gateways';
        $this->version = Settings::VERSION;
        $this->author = 'Varizet';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        $this->controllers = ['redirect', 'return', 'webhook', 'cron'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        parent::__construct();
        $this->displayName = $this->t('display_name');
        $this->description = $this->t('description');
    }

    public function install(): bool
    {
        if (!parent::install()
            || !$this->registerHook('paymentOptions')
            || !$this->registerHook('displayPaymentReturn')) {
            return false;
        }
        foreach (Repository::installSql(_DB_PREFIX_) as $sql) {
            if (!Db::getInstance()->execute($sql)) {
                return false;
            }
        }
        Configuration::updateValue('VARIZET_MODE', 'test');
        Configuration::updateValue('VARIZET_EXPIRES_IN', '1800');
        Configuration::updateValue('VARIZET_CURRENCY_UNIT', 'auto');
        Configuration::updateValue('VARIZET_CRON_TOKEN', bin2hex(random_bytes(16)));
        Configuration::updateValue('VARIZET_OS_AWAITING', $this->orderState('VARIZET_OS_AWAITING', 'state_awaiting', '#f2a900'));
        Configuration::updateValue('VARIZET_OS_REVIEW', $this->orderState('VARIZET_OS_REVIEW', 'state_review', '#7c4dff'));
        return true;
    }

    public function uninstall(): bool
    {
        // The mapping table and the order states stay: existing orders still reference them.
        foreach (array_merge(self::FIELDS, ['cron_token']) as $field) {
            Configuration::deleteByName('VARIZET_' . strtoupper($field));
        }
        return parent::uninstall();
    }

    /** Text from lang/fa.php for Persian shops, lang/en.php otherwise. */
    public function t(string $key): string
    {
        if ($this->strings === null) {
            $iso = isset($this->context->language) && is_object($this->context->language) ? (string) $this->context->language->iso_code : 'fa';
            $file = __DIR__ . '/lang/' . ($iso === 'fa' ? 'fa' : 'en') . '.php';
            $this->strings = is_file($file) ? (array) require $file : [];
        }
        return $this->strings[$key] ?? $key;
    }

    public function settings(): Settings
    {
        $states = [
            'order_status_pending' => (int) Configuration::get('VARIZET_OS_AWAITING'),
            'order_status_review' => (int) Configuration::get('VARIZET_OS_REVIEW'),
            'order_status_paid' => (int) Configuration::get('PS_OS_PAYMENT'),
            'order_status_expired' => (int) Configuration::get('PS_OS_CANCELED'),
            'order_status_canceled' => (int) Configuration::get('PS_OS_CANCELED'),
            'order_status_refunded' => (int) Configuration::get('PS_OS_REFUND'),
        ];
        return new Settings(function (string $key) use ($states) {
            $name = substr($key, strlen(Settings::PREFIX));
            if (isset($states[$name])) {
                return $states[$name];
            }
            return $name === 'status' ? (int) $this->active : Configuration::get('VARIZET_' . strtoupper($name));
        }, _PS_VERSION_);
    }

    public function gateway(): Gateway
    {
        $shop = new CallbackShop(
            function (int $orderId): ?array {
                $order = new Order($orderId);
                if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
                    return null;
                }
                $customer = new Customer((int) $order->id_customer);
                $address = new Address((int) $order->id_address_invoice);
                return [
                    'order_id' => (int) $order->id,
                    'order_status_id' => (int) $order->current_state,
                    'total' => (string) $order->total_paid,
                    'currency_code' => (string) (new Currency((int) $order->id_currency))->iso_code,
                    'firstname' => (string) $customer->firstname,
                    'lastname' => (string) $customer->lastname,
                    'telephone' => (string) ($address->phone_mobile ?: $address->phone),
                    'email' => (string) $customer->email,
                    'store_url' => (string) $this->context->shop->getBaseURL(true),
                ];
            },
            function (int $orderId, int $stateId, string $comment, bool $notify): void {
                $order = new Order($orderId);
                if ((int) $order->current_state !== $stateId) {
                    $history = new OrderHistory();
                    $history->id_order = $orderId;
                    $history->id_employee = 0;
                    $history->changeIdOrderState($stateId, $order);
                    $notify ? $history->addWithemail(true) : $history->add();
                    if ($stateId === (int) Configuration::get('PS_OS_PAYMENT')) {
                        $this->tagPayments($order);
                    }
                }
                if ($comment !== '') {
                    $message = new Message();
                    $message->message = mb_substr($comment, 0, 1600);
                    $message->id_order = $orderId;
                    $message->id_cart = (int) $order->id_cart;
                    $message->id_customer = (int) $order->id_customer;
                    $message->private = true;
                    $message->add();
                }
            },
            function (int $orderId): string {
                $order = new Order($orderId);
                return $this->context->link->getModuleLink($this->name, 'return', ['id_order' => $orderId, 'key' => $order->secure_key], true);
            },
            fn (string $key): string => $this->t($key),
            function (string $message): void {
                PrestaShopLogger::addLog('Varizet: ' . $message, 2, null, 'Module', (int) $this->id, true);
            },
        );
        return new Gateway($this->settings(), new Repository(new PrestaShopDb(), _DB_PREFIX_), $shop);
    }

    /** The payment PrestaShop records on «Payment accepted» gets the Varizet code as transaction id. */
    private function tagPayments(Order $order): void
    {
        $code = (new Repository(new PrestaShopDb(), _DB_PREFIX_))->latestForOrder((int) $order->id)?->code;
        if ($code === null) {
            return;
        }
        foreach ($order->getOrderPaymentCollection() as $payment) {
            if ((string) $payment->transaction_id === '') {
                $payment->transaction_id = $code;
                $payment->update();
            }
        }
    }

    /** Refuses checkout before a PrestaShop order exists when Varizet cannot take the amount. */
    public function precheck(float $total, string $currencyIso): ?string
    {
        if ($this->settings()->apiKey() === null) {
            return $this->t('error_not_configured');
        }
        try {
            $rial = Amount::toRialWithFactor($total, $this->settings()->factorFor($currencyIso));
        } catch (\Varizet\Exception\InvalidArgumentException) {
            return sprintf($this->t('error_currency'), $currencyIso);
        }
        return $rial < Money::MIN_ORDER_RIAL ? $this->t('error_min_amount') : null;
    }

    public function hookPaymentOptions(array $params): array
    {
        if (!$this->active || $this->settings()->apiKey() === null) {
            return [];
        }
        $currency = new Currency((int) $params['cart']->id_currency);
        try {
            $this->settings()->factorFor((string) $currency->iso_code);
        } catch (\Varizet\Exception\InvalidArgumentException) {
            return [];
        }
        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->t('payment_option'))
            ->setAction($this->context->link->getModuleLink($this->name, 'redirect', [], true))
            ->setAdditionalInformation('<p>' . htmlspecialchars($this->t('text_instruction'), ENT_QUOTES, 'UTF-8') . '</p>');
        return [$option];
    }

    /** Order confirmation page: the state, and the amount + link while still waiting. */
    public function hookDisplayPaymentReturn(array $params): string
    {
        $order = $params['order'] ?? null;
        if (!$this->active || !$order instanceof Order || $order->module !== $this->name) {
            return '';
        }
        $record = (new Repository(new PrestaShopDb(), _DB_PREFIX_))->latestForOrder((int) $order->id);
        $status = $record?->status ?? 'awaiting_payment';
        $message = match (true) {
            $status === 'paid' => 'return_paid',
            in_array($status, ['needs_review', 'underpaid'], true) => 'return_review',
            in_array($status, ['expired', 'canceled'], true) => 'return_closed',
            default => 'return_pending',
        };
        $this->context->smarty->assign([
            'varizet_message' => $this->t($message),
            'varizet_open' => $status === 'awaiting_payment' && $record?->paymentUrl !== null,
            'varizet_amount_html' => $record?->uniqueAmount !== null ? Format::uniqueAmountHtml($record->amountRial, $record->uniqueAmount) : '',
            'varizet_amount_label' => $this->t('unique_amount_label'),
            'varizet_payment_url' => $record?->paymentUrl,
            'varizet_pay_now' => $this->t('pay_now'),
            'varizet_code' => $record?->code,
        ]);
        return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }

    public function getContent(): string
    {
        $output = '';
        $connect = (string) Tools::getValue('varizet_connect');
        if ($connect === 'start') {
            $this->startOneClick();
        } elseif ($connect === 'callback') {
            $output .= $this->finishOneClick();
        }
        if (Tools::isSubmit('submitVarizet') || Tools::isSubmit('submitVarizetCheck')) {
            $errors = [];
            foreach (['api_key_live' => 'vz_live_', 'api_key_test' => 'vz_test_'] as $field => $prefix) {
                $value = trim((string) Tools::getValue('VARIZET_' . strtoupper($field)));
                if ($value !== '' && preg_match('/^' . $prefix . '[A-Za-z0-9_-]{8,200}$/D', $value) !== 1) {
                    $errors[] = $this->t('error_api_key');
                }
            }
            if ($errors !== []) {
                $output .= $this->displayError(implode('<br>', array_unique($errors)));
            } else {
                foreach (self::FIELDS as $field) {
                    $name = 'VARIZET_' . strtoupper($field);
                    $value = trim((string) Tools::getValue($name));
                    // Password inputs come back empty: keep the stored secret unless a new one is typed.
                    if (in_array($field, self::SECRET_FIELDS, true) && $value === '') {
                        continue;
                    }
                    Configuration::updateValue($name, $value);
                }
                $output .= $this->displayConfirmation($this->t('text_saved'));
            }
            if (Tools::isSubmit('submitVarizetCheck') && $errors === []) {
                $output .= $this->checkConnection();
            }
        }
        return $output . $this->renderInfo() . $this->renderForm();
    }

    /** The configure page itself; Varizet returns here with `varizet_connect=callback`. */
    private function configureUrl(array $extra = []): string
    {
        return $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name] + $extra);
    }

    private function oneClick(): \Varizet\PrestaShop\OneClick
    {
        $shopUrl = (string) $this->context->shop->getBaseURL(true);
        $webhook = $this->context->link->getModuleLink($this->name, 'webhook', [], true);
        $appUrl = defined('VARIZET_APP_URL') ? (string) constant('VARIZET_APP_URL') : null;
        return new \Varizet\PrestaShop\OneClick(
            $this->settings(),
            $shopUrl,
            \Varizet\PrestaShop\OneClick::webhookPathOf($webhook, $shopUrl),
            $appUrl,
        );
    }

    /** «اتصال یک‌کلیکی»: to the Varizet consent page (the PKCE flow in the encrypted admin cookie). */
    private function startOneClick(): void
    {
        $flow = [];
        $intent = Tools::getValue('intent');
        $url = $this->oneClick()->start(
            $this->configureUrl(['varizet_connect' => 'callback']),
            $flow,
            is_string($intent) && $intent !== '' ? $intent : null,
        );
        $this->context->cookie->__set(\Varizet\PrestaShop\OneClick::SESSION_KEY, (string) json_encode($flow[\Varizet\PrestaShop\OneClick::SESSION_KEY]));
        $this->context->cookie->write();
        header('Location: ' . $url, true, 302);
        exit;
    }

    /** Back from Varizet: store the key and webhook secret of the connected environment. */
    private function finishOneClick(): string
    {
        $key = \Varizet\PrestaShop\OneClick::SESSION_KEY;
        $stored = json_decode((string) $this->context->cookie->__get($key), true);
        $this->context->cookie->__unset($key);
        $this->context->cookie->write();
        $session = [$key => is_array($stored) ? $stored : null];
        $query = array_filter([
            'code' => Tools::getValue('code'),
            'state' => Tools::getValue('state'),
            'error' => Tools::getValue('error'),
        ], static fn ($v): bool => is_string($v) && $v !== '');
        $out = $this->oneClick()->finish($query, $session);
        if (!$out['ok']) {
            return $this->displayError($this->t($out['error'] ?? 'error_connect_expired'));
        }
        foreach ($out['values'] ?? [] as $name => $value) {
            Configuration::updateValue('VARIZET_' . strtoupper(substr($name, strlen(\Varizet\PrestaShop\Settings::PREFIX))), $value);
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return $this->displayConfirmation(sprintf($this->t('text_connect_done'), $e($out['store'] ?? ''), ($out['mode'] ?? 'test') === 'live' ? 'Live' : 'Test'));
    }

    private function checkConnection(): string
    {
        $settings = $this->settings();
        try {
            $client = $settings->client();
            if ($client === null) {
                return $this->displayError($this->t('error_api_key_required'));
            }
            $me = $client->me();
        } catch (\Varizet\Exception\ExceptionInterface $e) {
            return $this->displayError(sprintf($this->t('error_check'), htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')));
        }
        if ($me->livemode !== $settings->livemode()) {
            return $this->displayError(sprintf($this->t('error_check_mode'), $settings->livemode() ? 'Live' : 'Test'));
        }
        return $this->displayConfirmation(sprintf($this->t('text_check_ok'), htmlspecialchars($me->storeName, ENT_QUOTES, 'UTF-8'), $me->livemode ? 'Live' : 'Test'));
    }

    private function renderInfo(): string
    {
        $webhook = $this->context->link->getModuleLink($this->name, 'webhook', [], true);
        $cron = $this->context->link->getModuleLink($this->name, 'cron', ['token' => (string) Configuration::get('VARIZET_CRON_TOKEN')], true);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<div class="panel"><h3>' . $e($this->t('heading_title')) . '</h3>'
            . '<p><a class="btn btn-primary" href="' . $e($this->configureUrl(['varizet_connect' => 'start'])) . '"><i class="icon-link"></i> ' . $e($this->t('button_connect')) . '</a> '
            . '<span class="help-block" style="display:inline">' . $e($this->t('text_connect_help')) . '</span></p>'
            . '<p><strong>' . $e($this->t('text_webhook_url')) . ':</strong> <code dir="ltr">' . $e($webhook) . '</code><br><small>' . $e($this->t('help_webhook_url')) . '</small></p>'
            . '<p><strong>' . $e($this->t('text_cron_url')) . ':</strong> <code dir="ltr">' . $e($cron) . '</code><br><small>' . $e($this->t('help_cron_url')) . '</small></p></div>';
    }

    private function renderForm(): string
    {
        $select = static fn (array $options): array => ['query' => array_map(static fn ($id, $name) => ['id' => $id, 'name' => $name], array_keys($options), $options), 'id' => 'id', 'name' => 'name'];
        $fields = [
            ['type' => 'select', 'label' => $this->t('entry_mode'), 'name' => 'VARIZET_MODE', 'options' => $select(['test' => $this->t('text_mode_test'), 'live' => $this->t('text_mode_live')])],
            ['type' => 'password', 'label' => $this->t('entry_api_key_live'), 'name' => 'VARIZET_API_KEY_LIVE', 'desc' => $this->t('help_api_key')],
            ['type' => 'password', 'label' => $this->t('entry_api_key_test'), 'name' => 'VARIZET_API_KEY_TEST'],
            ['type' => 'password', 'label' => $this->t('entry_webhook_secret_live'), 'name' => 'VARIZET_WEBHOOK_SECRET_LIVE'],
            ['type' => 'password', 'label' => $this->t('entry_webhook_secret_test'), 'name' => 'VARIZET_WEBHOOK_SECRET_TEST'],
            ['type' => 'select', 'label' => $this->t('entry_currency_unit'), 'name' => 'VARIZET_CURRENCY_UNIT', 'desc' => $this->t('help_currency_unit'), 'options' => $select(['auto' => $this->t('text_unit_auto'), 'IRR' => $this->t('text_unit_irr'), 'IRT' => $this->t('text_unit_irt')])],
            ['type' => 'text', 'label' => $this->t('entry_expires_in'), 'name' => 'VARIZET_EXPIRES_IN', 'desc' => $this->t('help_expires_in'), 'class' => 'fixed-width-sm'],
            ['type' => 'text', 'label' => $this->t('entry_api_base_url'), 'name' => 'VARIZET_API_BASE_URL', 'desc' => $this->t('help_api_base_url')],
            ['type' => 'switch', 'label' => $this->t('entry_debug'), 'name' => 'VARIZET_DEBUG', 'is_bool' => true, 'values' => [['id' => 'on', 'value' => 1, 'label' => $this->t('text_enabled')], ['id' => 'off', 'value' => 0, 'label' => $this->t('text_disabled')]]],
        ];
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitVarizet';
        foreach (self::FIELDS as $field) {
            $name = 'VARIZET_' . strtoupper($field);
            $helper->fields_value[$name] = in_array($field, self::SECRET_FIELDS, true) ? '' : (string) Configuration::get($name);
        }
        return $helper->generateForm([[
            'form' => [
                'legend' => ['title' => $this->t('text_edit'), 'icon' => 'icon-cogs'],
                'input' => $fields,
                'submit' => ['title' => $this->t('button_save')],
                'buttons' => [['type' => 'submit', 'name' => 'submitVarizetCheck', 'title' => $this->t('button_check'), 'icon' => 'process-icon-refresh', 'class' => 'pull-left']],
            ],
        ]]);
    }

    private function orderState(string $configKey, string $labelKey, string $color): int
    {
        $existing = (int) Configuration::get($configKey);
        if ($existing > 0 && Validate::isLoadedObject(new OrderState($existing))) {
            return $existing;
        }
        $state = new OrderState();
        $state->name = [];
        foreach (Language::getLanguages(false) as $language) {
            $fa = $language['iso_code'] === 'fa';
            $strings = require __DIR__ . '/lang/' . ($fa ? 'fa' : 'en') . '.php';
            $state->name[(int) $language['id_lang']] = $strings[$labelKey];
        }
        $state->module_name = $this->name;
        $state->color = $color;
        $state->send_email = false;
        $state->logable = false;
        $state->unremovable = true;
        $state->hidden = false;
        $state->paid = false;
        $state->add();
        return (int) $state->id;
    }
}
