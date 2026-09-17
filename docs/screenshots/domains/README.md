# Domain interface verification

Captured on 2026-09-16 using an isolated SQLite installation with fictitious Cloudflare connections. Desktop: 1440 px; mobile: 390 px. No live Cloudflare credentials or DNS writes were used.

- `registry-desktop.png`, `registry-mobile.png`: searchable domain registry, independent DNS and assignment statuses.
- `add-wildcard-desktop.png`, `add-wildcard-mobile.png`: connection method, exact/subdomain/wildcard scope, account-grouped zones, optional landing.
- `cloudflare-desktop.png`: multiple token connections and real account/zone grouping.
- `assignment-review-desktop.png`: explicit source/destination review.
- `landing-desktop.png`, `landing-mobile.png`, `landing-transfer-review.png`: root assignment, wildcard child creation and transfer review.

Browser checks covered manual subdomain creation, assignment, returning from review, transfer, retaining DNS during removal, search/stat filters, wildcard child creation, and stale transfer rejection after another session unassigned the address. No JavaScript errors or horizontal overflow at 390 px were observed.

Related automated checks: 193 PHP tests / 1,129 assertions passed, 35 JavaScript tests passed, Pint passed, production asset build passed. A subsequent full-suite run passed 599 tests with one failure in `LandingTemplatesTest::test_invalid_replacement_preserves_template_details_package_and_releases`: the test still expects `.php` template uploads to be rejected while concurrent template changes allow them. Domain-related checks remained green.
