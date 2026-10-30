<?php

/**
 * Checkout: validates the cart into an order «در انتظار پرداخت واریزت», creates the Varizet order and
 * redirects to the hosted payment page. `?id_order=&key=` retries an existing order.
 */
class VarizetRedirectModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess(): void
    {
        /** @var Varizet $module */
        $module = $this->module;
        $orderId = (int) Tools::getValue('id_order');
        if ($orderId > 0) {
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order) || $order->module !== $module->name || !hash_equals((string) $order->secure_key, (string) Tools::getValue('key'))) {
                Tools::redirect('index.php?controller=history');
            }
        } else {
            $cart = $this->context->cart;
            if (!$module->active || !$cart->id || !$cart->id_customer || !$cart->id_address_invoice) {
                Tools::redirect('index.php?controller=order&step=1');
            }
            $allowed = false;
            foreach (Module::getPaymentModules() as $m) {
                $allowed = $allowed || $m['name'] === $module->name;
            }
            $customer = new Customer((int) $cart->id_customer);
            if (!$allowed || !Validate::isLoadedObject($customer)) {
                Tools::redirect('index.php?controller=order&step=1');
            }
            $currency = $this->context->currency;
            $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
            $refusal = $module->precheck($total, (string) $currency->iso_code);
            if ($refusal !== null) {
                $this->errorPage($refusal, null);
                return;
            }
            $module->validateOrder((int) $cart->id, (int) Configuration::get('VARIZET_OS_AWAITING'), $total, $module->displayName, null, [], (int) $currency->id, false, $customer->secure_key);
            $orderId = (int) $module->currentOrder;
            $order = new Order($orderId);
        }

        $result = $module->gateway()->start($orderId);
        if (isset($result['redirect'])) {
            Tools::redirect($result['redirect']);
        }
        $this->errorPage($result['error'] ?? $module->t('error_network'), $order);
    }

    private function errorPage(string $message, ?Order $order): void
    {
        /** @var Varizet $module */
        $module = $this->module;
        $this->context->smarty->assign([
            'varizet_title' => $module->t('error_title'),
            'varizet_message' => $message,
            'varizet_retry_url' => $order === null ? null : $this->context->link->getModuleLink($module->name, 'redirect', ['id_order' => (int) $order->id, 'key' => $order->secure_key], true),
            'varizet_retry' => $module->t('retry'),
            'varizet_orders_url' => $this->context->link->getPageLink($order === null ? 'order' : 'history', true),
            'varizet_back' => $module->t('back_to_orders'),
        ]);
        $this->setTemplate('module:varizet/views/templates/front/error.tpl');
    }
}
