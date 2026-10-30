<?php

/** Signed Varizet webhooks (POST, `Varizet-Signature`). */
class VarizetWebhookModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $ajax = true;

    public function postProcess(): void
    {
        /** @var Varizet $module */
        $module = $this->module;
        $result = $module->gateway()->webhook(
            (string) file_get_contents('php://input'),
            \Varizet\Webhook\Webhook::headerFromServer($_SERVER),
        );
        http_response_code($result['status']);
        header('Content-Type: application/json');
        echo json_encode($result['body']);
        exit;
    }
}
