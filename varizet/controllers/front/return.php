<?php

/** The customer is back from the payment page: sync once, then PrestaShop's order confirmation. */
class VarizetReturnModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess(): void
    {
        $orderId = (int) Tools::getValue('id_order');
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name || !hash_equals((string) $order->secure_key, (string) Tools::getValue('key'))) {
            Tools::redirect('index.php');
        }
        /** @var Varizet $module */
        $module = $this->module;
        $module->gateway()->sync($orderId);
        Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int) $order->id_cart . '&id_module=' . (int) $module->id . '&id_order=' . $orderId . '&key=' . $order->secure_key);
    }
}
