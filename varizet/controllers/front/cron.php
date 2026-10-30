<?php

/** Polling fallback: call `…/module/varizet/cron?token=…` every 5 minutes. */
class VarizetCronModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $ajax = true;

    public function postProcess(): void
    {
        $token = (string) Configuration::get('VARIZET_CRON_TOKEN');
        header('Content-Type: application/json');
        if ($token === '' || !hash_equals($token, (string) Tools::getValue('token'))) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }
        /** @var Varizet $module */
        $module = $this->module;
        echo json_encode($module->gateway()->poll(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        exit;
    }
}
