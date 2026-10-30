# Varizet for PrestaShop

PrestaShop 8 payment module «واریزت»: the payment option validates the cart into an order in
«در انتظار پرداخت واریزت», creates the Varizet order and redirects to the hosted payment page;
signed webhooks, the customer's return and a cron URL move the order to «Payment accepted». Uses
the PHP SDK ([varizet/varizet-php](https://github.com/Varizet/varizet-php)), bundled in the zip. Kind in `Varizet-Plugin`: `prestashop/1.1.0; ps=<version>; sdk=…; php=…`.

## Layout

```
varizet/                          the module (zip root)
├─ varizet.php                    PaymentModule: install (table, 2 order states, defaults),
│                                 settings form, paymentOptions, displayPaymentReturn, PrestaShop
│                                 glue for the gateway (orders, OrderHistory, private messages)
├─ controllers/front/redirect.php validate cart → start → payment page (retry link on errors)
├─ controllers/front/return.php   sync once → order-confirmation
├─ controllers/front/webhook.php  signed webhooks
├─ controllers/front/cron.php     ?token= polling fallback
├─ src/                           Varizet\PrestaShop\: Gateway, StatusMap, Repository, Settings, …
│                                 (the OpenCart design, ported; PrestaShopDb over Db::getInstance())
├─ lang/{fa,en}.php               strings (Persian for fa shops, English otherwise)
└─ views/templates/{front,hook}/  error page, confirmation block (unique amount + pay link)
bin/build-zip.sh                  zip (+ --stage <dir> for dev); adds index.php guards
dev/                              docker compose (PrestaShop 8.2 + MariaDB), configure/simulate
                                  scripts, Playwright checkout (dev/e2e/checkout.py)
```

Order states: pending = «در انتظار پرداخت واریزت» (created on install), review = «در انتظار
بررسی واریزت» (created), paid = `PS_OS_PAYMENT`, expired and canceled = `PS_OS_CANCELED` (stock
returns), refunded = `PS_OS_REFUND`. On «Payment accepted» PrestaShop records the payment; the
module sets its transaction id to the Varizet code. Notes go to private order messages.

## Settings

Mode, API key Live/Test, webhook secret Live/Test (empty password fields keep the stored value),
currency unit (auto / ریال / تومان), payment window, API URL (advanced), debug. «بررسی اتصال»
saves and calls `GET /v1/me`. The configure page shows the webhook URL and the cron URL.

## Tests

```bash
bin/sdk.sh              # PHP SDK: the monorepo copy, or fetched into .sdk/ (standalone)
composer install
vendor/bin/phpunit
# without a local PHP:
# docker run --rm -v "$PWD":/app -w /app composer:2 sh -c 'bin/sdk.sh && composer install && vendor/bin/phpunit'
bin/build-zip.sh        # dist/varizet-prestashop-1.1.0.zip + .sha256
```

### Smoke test

Run on 2026-10-29 with PrestaShop 8.2.1 (PHP 8.1) against the Varizet sandbox: three guest
checkouts redirected to the hosted payment page with the order total; after a simulated deposit
one order became «Payment accepted» through the cron poll, one through a signed webhook (≈ 4 s)
and one through the customer's return URL. `dev/` holds the setup: `docker-compose.yml`
(PrestaShop on `localhost:8092`), `configure.php` (sets the key and secret from
`VARIZET_API_KEY`/`VARIZET_WEBHOOK_SECRET`), `simulate.php <id_order>` (`POST /v1/test/deposits`)
and `e2e/checkout.py` (a guest checkout). Webhook URLs must use the shop's own host:
PrestaShop redirects other hosts with a 302, which Varizet does not follow.

## One-click connect

«اتصال یک‌کلیکی به واریزت» on the configure page (`…&configure=varizet&varizet_connect=start`) →
`OneClick::start()` with the PKCE flow in the encrypted admin cookie and the configure page
(`varizet_connect=callback`, PrestaShop 8's Symfony URL is accepted) as `redirect_uri` → the Varizet
consent page → back to the configure page, which exchanges the code and stores
`VARIZET_API_KEY_<MODE>` + `VARIZET_WEBHOOK_SECRET_<MODE>` (and `VARIZET_MODE` when no key existed).
`OneClick::webhookPathOf()` reports the friendly webhook path (with the language segment) or the
`index.php?fc=module&module=varizet&controller=webhook` form. The admin folder is random, so the
panel cannot open it: the connect starts here. Manual entry stays. Dev: `define('VARIZET_APP_URL', …)`.

## Needs from the API

Nothing open; the shared OpenCart/PrestaShop sync engine could move into the SDK later.

---

## Contributing and security

Issues and pull requests are welcome. This repository is published from Varizet's main repository:
accepted changes are applied there and appear here with the next release.

Please report security vulnerabilities privately to support@varizet.ir, not in public issues.

## License

MIT, see [LICENSE](LICENSE).
