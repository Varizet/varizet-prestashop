<?php

declare(strict_types=1);

namespace Varizet\PrestaShop;

use Varizet\Exception\InvalidArgumentException;
use Varizet\Http\HttpClientInterface;
use Varizet\Util\Amount;
use Varizet\Util\IdempotencyKey;
use Varizet\VarizetClient;

/**
 * Typed settings. Keys are read as `payment_varizet_<name>` through a mapper; the module maps
 * them to PrestaShop `Configuration` names (`VARIZET_<NAME>`, order states to `PS_OS_*`).
 */
final class Settings
{
    public const PREFIX = 'payment_varizet_';
    public const VERSION = '1.1.0';

    /** @internal Tests replace the transport. */
    public static ?HttpClientInterface $transport = null;

    /** @var callable(string): mixed */
    private $get;

    /**
     * @param callable(string): mixed $get Reads a full config key (`$this->config->get(...)`).
     */
    public function __construct(callable $get, private readonly string $platformVersion = '')
    {
        $this->get = $get;
    }

    public function raw(string $key): mixed
    {
        return ($this->get)(self::PREFIX . $key);
    }

    private function str(string $key, string $default = ''): string
    {
        $v = $this->raw($key);
        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : $default;
    }

    public function enabled(): bool
    {
        return (bool) $this->raw('status');
    }

    public function livemode(): bool
    {
        return $this->str('mode', 'test') === 'live';
    }

    public function apiKey(?bool $live = null): ?string
    {
        $key = $this->str(($live ?? $this->livemode()) ? 'api_key_live' : 'api_key_test');
        return $key === '' ? null : $key;
    }

    /** @return array<string, string> */
    public function webhookSecrets(): array
    {
        return array_filter([
            'live' => $this->str('webhook_secret_live'),
            'test' => $this->str('webhook_secret_test'),
        ], static fn (string $s): bool => $s !== '');
    }

    public function baseUrl(): string
    {
        $url = $this->str('api_base_url');
        if ($url === '' && defined('VARIZET_API_BASE_URL')) {
            $url = (string) constant('VARIZET_API_BASE_URL');
        }
        return $url === '' ? VarizetClient::DEFAULT_BASE_URL : $url;
    }

    public function expiresIn(): int
    {
        $v = (int) $this->str('expires_in', '1800');
        return max(300, min(86_400, $v > 0 ? $v : 1800));
    }

    /** Order state ids (the mapper supplies PrestaShop's and the module's states). */
    public function statusId(string $which): int
    {
        $defaults = ['pending' => 1, 'paid' => 2, 'review' => 1, 'expired' => 14, 'canceled' => 7, 'refunded' => 11];
        return (int) $this->str('order_status_' . $which, (string) ($defaults[$which] ?? 1));
    }

    public function cronToken(): string
    {
        return $this->str('cron_token');
    }

    public function debug(): bool
    {
        return (bool) $this->raw('debug');
    }

    public function factorFor(string $currencyCode): int
    {
        $unit = $this->str('currency_unit', 'auto');
        if ($unit === 'IRR' || $unit === 'IRT') {
            return Amount::factor($unit);
        }
        if (!Amount::isSupported($currencyCode)) {
            throw new InvalidArgumentException('currency_unsupported');
        }
        return Amount::factor($currencyCode);
    }

    public function siteFingerprint(string $storeUrl): string
    {
        return IdempotencyKey::siteFingerprint('prestashop', $storeUrl);
    }

    public function client(?bool $live = null): ?VarizetClient
    {
        $key = $this->apiKey($live);
        if ($key === null) {
            return null;
        }
        return new VarizetClient($key, array_filter([
            'http_client' => self::$transport,
            'base_url' => $this->baseUrl(),
            'plugin' => [
                'kind' => 'prestashop',
                'version' => self::VERSION,
                'meta' => $this->platformVersion === '' ? [] : ['ps' => $this->platformVersion],
            ],
        ], static fn ($v): bool => $v !== null));
    }
}
