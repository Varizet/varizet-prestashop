# Changelog

## [1.1.0] - 2026-10-29

- «اتصال یک‌کلیکی به واریزت» on the configure page: PKCE flow in the encrypted admin cookie, the
  browser comes back to the configure page (`varizet_connect=callback`) and the API key and webhook
  secret of the approved environment are stored. The module reports its webhook path (friendly URL
  with the language segment, or the `index.php?fc=module…` form). Manual key entry is unchanged.
- PHP SDK 1.1.0 bundled.

## [1.0.0] - 2026-10-29

- First release for PrestaShop 8: «واریزت» payment option, settings with Test/Live keys and webhook
  secrets and «بررسی اتصال», order states «در انتظار پرداخت واریزت» / «در انتظار بررسی واریزت»,
  checkout → hosted payment page (retry link on errors), signed webhook, return sync, token cron
  URL for polling, unique amount on the confirmation page, Persian and English strings.
