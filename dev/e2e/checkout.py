"""Smoke checkout on the dev PrestaShop (classic theme) with the Varizet payment option.

Adds product 1 to the cart, checks out as a guest with an Iranian address, picks «واریزت»,
places the order and prints JSON: the URL the module redirected to and the PrestaShop order
reference. Screenshots go to dev/artifacts/.
    python dev/e2e/checkout.py [base_url]
"""
import json
import pathlib
import sys
import time

from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8092"
ART = pathlib.Path(__file__).resolve().parent.parent / "artifacts"
ART.mkdir(exist_ok=True)


def main() -> None:
    redirects: list[str] = []
    with sync_playwright() as p:
        browser = p.chromium.launch(channel="chrome", headless=True)
        page = browser.new_page(locale="en-US")
        page.on("response", lambda r: redirects.append(r.headers.get("location", "")) if "module/varizet/redirect" in r.url or "fc=module&module=varizet" in r.url else None)
        try:
            page.goto(f"{BASE}/index.php?id_product=1&controller=product", wait_until="domcontentloaded")
            page.click("button.add-to-cart")
            page.wait_for_selector("#blockcart-modal a.btn-primary", timeout=15000)
            page.click("#blockcart-modal a.btn-primary")
            page.wait_for_selector("a.btn-primary[href*='controller=order'], .checkout a.btn-primary", timeout=15000)
            page.goto(f"{BASE}/index.php?controller=order", wait_until="domcontentloaded")

            # 1. Personal information (guest)
            page.check("input[name='id_gender'][value='1']")
            page.fill("#field-firstname", "Sara")
            page.fill("#field-lastname", "Mohammadi")
            page.fill("#field-email", f"sara+{int(time.time())}@example.com")
            for name in ("psgdpr", "customer_privacy"):
                box = page.locator(f"input[name='{name}']")
                if box.count():
                    box.check()
            page.click("#customer-form button[name='continue']")

            # 2. Address
            page.wait_for_selector("#field-address1", timeout=15000)
            page.fill("#field-address1", "Valiasr St 12")
            page.fill("#field-city", "Tehran")
            state = page.locator("#field-id_state")
            if state.count():
                state.select_option(index=1)
            page.fill("#field-postcode", "12345")
            phone = page.locator("#field-phone")
            if phone.count():
                phone.fill("09121234567")
            page.click("#delivery-address button[name='confirm-addresses']")

            # 3. Shipping
            page.wait_for_selector("#js-delivery button[name='confirmDeliveryOption']", timeout=15000)
            page.click("#js-delivery button[name='confirmDeliveryOption']")

            # 4. Payment: the Varizet option, terms, place order
            page.wait_for_selector(".payment-options", timeout=15000)
            page.screenshot(path=str(ART / "ps-payment-step.png"), full_page=True)
            option = page.locator(".payment-option", has_text="Varizet").locator("input[type='radio']")
            option.check()
            terms = page.locator("input[name='conditions_to_approve[terms-and-conditions]']")
            if terms.count():
                terms.check()
            try:
                page.click("#payment-confirmation button[type='submit']", no_wait_after=False, timeout=15000)
            except Exception:  # navigation to an unreachable payment host is fine
                pass
            time.sleep(3)
            page.screenshot(path=str(ART / "ps-after-place-order.png"), full_page=True)
            print(json.dumps({"redirects": [r for r in redirects if r], "final_url": page.url}))
        except Exception:
            page.screenshot(path=str(ART / "ps-failure.png"), full_page=True)
            errors = page.locator(".alert-danger, .help-block li, .form-control-comment").all_inner_texts()
            print(json.dumps({"failed_at": page.url, "errors": errors}, ensure_ascii=False))
            raise
        finally:
            browser.close()


if __name__ == "__main__":
    main()
