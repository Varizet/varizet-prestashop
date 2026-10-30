<?php

declare(strict_types=1);

namespace Varizet\PrestaShop;

use Varizet\Connect\ConnectOutcome;
use Varizet\Connect\OneClickConnect;
use Varizet\VarizetClient;

/**
 * «اتصال یک‌کلیکی» (docs/10 §1.4) on the module's configure page: `start()` sends the admin to the
 * Varizet consent page (the PKCE flow is kept in the admin cookie), `finish()` exchanges the
 * returned code and gives the settings to store (key + webhook secret of the connected
 * environment). The webhook URL depends on friendly URLs and the language segment, so the module
 * reports the path it serves (`webhookPathOf()`).
 */
final class OneClick
{
    public const SESSION_KEY = 'varizet_connect';
    /** Works with and without friendly URLs. */
    public const DEFAULT_WEBHOOK_PATH = '/index.php?fc=module&module=varizet&controller=webhook';

    public function __construct(
        private readonly Settings $settings,
        /** The shop's base URL (the site that connects). */
        private readonly string $catalogUrl,
        private readonly string $webhookPath,
        private readonly ?string $appUrl = null,
    ) {
    }

    /**
     * @param string               $redirectUri the configure page with `varizet_connect=callback`
     * @param array<string, mixed> $session     the decoded admin-cookie value
     */
    public function start(string $redirectUri, array &$session, ?string $intent = null, ?int $now = null): string
    {
        $start = $this->connect()->start(array_filter([
            'client' => 'prestashop',
            'site_url' => $this->siteUrl(),
            'redirect_uri' => $redirectUri,
            'plugin_version' => Settings::VERSION,
            'webhook_path' => $this->webhookPath,
            'intent' => $intent !== null && preg_match('/^[A-Za-z0-9._~-]{10,400}$/D', $intent) === 1 ? $intent : null,
        ], static fn ($v): bool => $v !== null), $now);
        $session[self::SESSION_KEY] = $start->flow();
        return $start->url;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $session
     * @return array{ok: bool, error?: string, values?: array<string, string>, mode?: string, store?: string}
     *         `error` is a language key; `values` the `payment_varizet_*` settings to store.
     */
    public function finish(array $query, array &$session, ?int $now = null): array
    {
        $flow = is_array($session[self::SESSION_KEY] ?? null) ? $session[self::SESSION_KEY] : null;
        unset($session[self::SESSION_KEY]);
        $outcome = $this->connect()->finish($query, $flow, $this->siteUrl(), $now);
        if (!$outcome->ok() || $outcome->result === null) {
            return ['ok' => false, 'error' => match ($outcome->error) {
                ConnectOutcome::CANCELLED => 'error_connect_cancelled',
                ConnectOutcome::EXCHANGE_FAILED => 'error_connect_exchange',
                default => 'error_connect_expired',
            }];
        }
        $result = $outcome->result;
        $mode = $result->mode();
        $values = [
            Settings::PREFIX . 'api_key_' . $mode => $result->apiKey,
            Settings::PREFIX . 'webhook_secret_' . $mode => $result->webhookSecret,
        ];
        if ($this->settings->apiKey() === null) {
            $values[Settings::PREFIX . 'mode'] = $mode;
        }
        return ['ok' => true, 'values' => $values, 'mode' => $mode, 'store' => $result->storeName];
    }

    private function siteUrl(): string
    {
        return rtrim($this->catalogUrl, '/');
    }

    private function connect(): OneClickConnect
    {
        $appUrl = $this->appUrl ?? (defined('VARIZET_APP_URL') ? (string) constant('VARIZET_APP_URL') : OneClickConnect::DEFAULT_APP_URL);
        return VarizetClient::oneClickConnect(array_filter([
            'app_url' => $appUrl,
            'base_url' => $this->settings->baseUrl(),
            'http_client' => Settings::$transport,
            'plugin' => ['kind' => 'prestashop', 'version' => Settings::VERSION],
        ], static fn ($v): bool => $v !== null));
    }

    /**
     * The webhook path to report: the module link relative to the shop URL when it is one of the
     * forms Varizet accepts (friendly URL, optionally behind a language segment), else the query
     * form, which PrestaShop serves either way.
     */
    public static function webhookPathOf(string $moduleLink, string $shopUrl): string
    {
        $base = rtrim($shopUrl, '/');
        if (str_starts_with($moduleLink, $base . '/')) {
            $path = substr($moduleLink, strlen($base));
            if (preg_match('#^/(?:[a-z]{2}(?:-[a-z]{2})?/)?module/varizet/webhook$#D', $path) === 1) {
                return $path;
            }
        }
        return self::DEFAULT_WEBHOOK_PATH;
    }
}
