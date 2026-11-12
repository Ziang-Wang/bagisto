# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## This fork runs a live store: URmotorparts

This checkout is the code behind **https://www.urmotorparts.com**, a motorcycle-parts store migrated from WordPress/WooCommerce and live since July 2026. Real customers, orders and PayPal payments go through it, and staff work in the admin panel directly on production. The production branch is `feature/woocommerce-importer` of the public fork `Ziang-Wang/bagisto` (`origin`); `upstream` is bagisto/bagisto, base branch `2.4`. The sections after this one are upstream's generic Bagisto guide. Where they disagree with this section, this section wins.

### Ground rules

- The repository is public. Never commit secrets or private data: payment keys, SMTP or app passwords, admin logins, the server address or SSH details, customer data, database dumps. These live only in the production database and `.env`.
- The owner's workflow: change the code locally, test it for real on the local store (curl or a browser, not just by reading the code), commit only when asked, deploy to production only when asked. Writing secrets into production needs the owner's explicit approval each time.
- Before writing to the production database, take a fresh dump with `sudo urm-backup db` on the host.
- Never run `woocommerce:migrate` against production. Already-imported products are skipped, `--fresh` imports everything again as duplicates, and `--with-content` (part of `--all`) deletes every theme customization (home-page sections, footer links) and every CMS page the importer did not create, then rebuilds them from the WooCommerce data.
- Keep edits to core packages small and explain them in a comment. Storefront templates are edited in place in `packages/Webkul/Shop` (there is no separate theme package); add new behaviour from `app/` through events or config where possible (see `app/Providers/AppServiceProvider.php`).

### Where the store-specific code lives

| What | Where |
|---|---|
| WooCommerce importer (`php artisan woocommerce:migrate`) | `packages/Webkul/WooImporter`; `woocommerce` connection in `config/database.php`; `WOO_*` in `.env.example` |
| Ops runbook: migration, image build, HTTPS go-live behind Caddy, backups and restores. Written in Chinese for the owner; keep it in Chinese. | `packages/Webkul/WooImporter/MIGRATION_GUIDE.md` |
| Production image: nginx, php-fpm and MySQL under supervisor in one container, built from this repo | `docker/production/` (build context is the repo root) |
| Backup script and cron entry for the production host | `docker/production/backup/` |
| Country-based shipping rates | `config/country-shipping.php`, `app/Shipping/Carriers/CountryRate.php`, registered in `AppServiceProvider` |
| Google Analytics 4 with Consent Mode v2 and e-commerce events | `config/analytics.php`, `app/Analytics/` |
| HTML allowed in product, category and CMS descriptions. Anything the admin TinyMCE toolbar can insert must be whitelisted here or it is stripped on save; iframes (so YouTube embeds) are stripped today. | `config/purify.php` |
| Storefront look: header, footer, nav order, Contact Us page, cookie banner, description styling | `packages/Webkul/Shop/src/Resources/views/` |

- `resources/views/` is git-ignored (upstream ships a `.gitignore` containing `*` there), so app-level Blade views live under `app/` with their own view namespace, like `app/Analytics/views`.
- The compiled theme assets in `public/themes/*/build` are upstream's and have never been rebuilt in this fork. Tailwind only generated the classes upstream uses, so new styling either reuses a class already in `public/themes/shop/default/build/assets/*.css` or goes into a scoped `@push('styles')` block (see `.um-product-description` in `products/view.blade.php`).

### Settings that exist only in the production database

Set in the admin panel or by one-off scripts, not by code or the importer, so a fresh install or a re-migration won't recreate them. As of November 2026:

- Payments: PayPal Smart Button (live credentials) and Money Transfer; every other method is disabled.
- Shipping: Bagisto's Flat Rate and Free Shipping carriers are disabled in favour of CountryRate; cart rule "Free Shipping Over $200".
- EU 15% tax rates (created by the importer's `--with-tax`).
- Outgoing mail: Gmail SMTP set in admin (`MAIL_MAILER=bagisto-dynamic-smtp`).
- GDPR cookie banner, the sitemap record, and the channel's SEO title and description.
- Product share buttons for Facebook, X and Pinterest (Configure → Catalog → Products → Social Share). The store's own Facebook, Instagram and YouTube links are in code, in the footer template.

### Environments

- **Local store:** container `urmotorparts` at http://localhost:8088 (image `urmotorparts:prod`, volumes `urmotorparts-mysql` and `urmotorparts-storage`), with PayPal in sandbox mode. The code is baked into the image, not bind-mounted, so copy changed files in with `docker cp`. The checkout itself has no `.env`; host PHP only runs `pint` and `pest`.
- **Production:** container `urmotorparts` from the same image on a Tencent Cloud host, published on `127.0.0.1:8080` behind Caddy (`/etc/caddy/Caddyfile`: HTTPS, `urmotorparts.com` redirects to `www`), volumes `urm-mysql` and `urm-storage`. Docker there is the snap package, so commands need `sudo docker`. The host clock is Beijing time (Asia/Shanghai), which is also how the owner reports times; the container and database run on UTC. DNS is managed at Aliyun. SSH details are not in this repo; ask the owner.
- Both containers' `.env` was copied from `.env.example`, so `APP_ENV=local` even in production. Never branch on `APP_ENV` or `app()->environment()`; key off the `APP_URL` host, as `config/analytics.php` does.
- `QUEUE_CONNECTION=sync`: mail and queued jobs run inside the request.
- No scheduler runs (the image has no cron and no `schedule:work`), so Bagisto's scheduled commands (price and catalog-rule indexing, invoice reminders, campaigns) never run, and the sitemap is only regenerated by hand (Marketing → Search & SEO → Sitemaps).

### Working inside the containers

- Run artisan as the web user: `docker exec -u www-data -e HOME=/tmp -w /var/www/bagisto urmotorparts php artisan …` (on production, `sudo docker …` over SSH). Running it as root leaves root-owned cache files, and php-fpm then answers with 500s.
- OPcache runs with `validate_timestamps=0`. After changing any PHP or Blade file, or running `config:cache` or `route:cache`, restart php-fpm with `docker exec urmotorparts supervisorctl restart php-fpm`, as a command of its own: once an earlier step of an `&&` chain failed, the restart was skipped and the old code kept serving.
- Config and routes are cached; re-run `config:cache` and `route:cache` after changing either.
- Whole pages are cached for 7 days (Spatie ResponseCache through `packages/Webkul/FPC`, in the same file store as the app cache): the home page, the catch-all product/category route, CMS pages, contact, search and compare. The cache key drops the query string except `?query=` on search (`packages/Webkul/FPC/src/Hasher/DefaultHasher.php`). After storefront changes run `php artisan responsecache:clear`; admin saves clear it automatically.
- On every start the entrypoint runs `chown -R` over `storage/framework`. On 2026-10-23 `storage/framework/cache/data` held 5.4 million files, and the container could not finish starting. Count the files there before restarting or recreating the production container.

### Deploying to production

Production is updated by copying files into the running container, not by rebuilding the image:

1. Commit and push the branch.
2. `scp` the changed files to the host, copy them into the container (`sudo docker cp`, or pipe a tarball into `sudo docker exec -i urmotorparts tar xzf - -C /var/www/bagisto`) and `chown www-data:www-data` them.
3. As www-data, run `config:cache`, `route:cache` or `view:clear` as needed, then `responsecache:clear`.
4. Restart php-fpm, then check https://www.urmotorparts.com with curl.

So the `urmotorparts:prod` image on the host (built 2026-07-26 from the host's clone `~/bagisto` at `cea37c6fac`) is older than the code in the running container, which matched the branch head on 2026-11-08 apart from importer sources and docs. Data survives recreating the container because it is in the volumes; code does not. Before `docker rm` and `docker run`, `git pull` the host clone and rebuild the image (MIGRATION_GUIDE.md §2 and §7.1), or every fix since July is lost. A rebuilt image also generates a new `APP_KEY` (`build-install.sh` runs `key:generate`), which signs everyone out and breaks any admin two-factor secret; pass the old key with `-e APP_KEY=…` (the entrypoint writes it into `.env`).

### Backups and recovery

- Host cron (`/etc/cron.d/urm-backup` runs `/usr/local/bin/urm-backup`): a database dump daily at 03:30 Beijing time, newest 30 kept in `/home/ubuntu/backup/db`; a `storage/app` tarball on Sundays at 04:00, newest 4 kept in `/home/ubuntu/backup/images`; log in `/home/ubuntu/backup/backup.log`. The backups sit on the same disk as the site, and there are no cloud snapshots.
- MySQL keeps 30 days of binlog (ROW format), but InnoDB foreign-key cascades are not written to it. Deleting a category in admin also deletes its translations and product links, and those only come back from a dump. Staff deleted every category by mistake on 2026-11-02 and again on 2026-11-03.
- Restore steps are in MIGRATION_GUIDE.md §8.2: the whole database, or load a dump into a temporary `bagisto_restore` database and copy rows back.

### Testing here

- Run `vendor/bin/pint --test` on the PHP files you touched.
- Host PHP has `pdo_mysql` but no `pdo_sqlite`, so pest needs a MySQL server: start a throwaway `mysql:8.0` container (for example on `127.0.0.1:33306`), pass `APP_KEY` and the `DB_*` variables on the command line, and remove the container afterwards. Tests use `DatabaseTransactions` and do not migrate, so tests that touch tables need the schema installed first, the way CI runs `php artisan bagisto:install --skip-env-check --skip-admin-creation --skip-github-star`.
- Check storefront behaviour on the local store with curl or a headless browser (Playwright). GA4 only loads on the live host. To test it locally, set `GA4_ENABLED=true` in the container's `.env`, run `config:cache` and restart php-fpm, and remove it afterwards; block requests to `/g/collect` in browser tests so they don't reach the real GA4 property.

### Known quirks

- The admin's Create Order page only accepts Cash on Delivery and Money Transfer (`packages/Webkul/Admin/src/Http/Controllers/Sales/OrderController.php`), so Money Transfer must stay enabled; PayPal works only at customer checkout.
- `/` ignores its query string, and the catch-all route (`ProductsCategoriesProxyController`) renders the home page for paths with characters outside the slug pattern (such as `.` or `:`); both answer 200. Search Console lists about a million spam URLs left by the hacked WordPress site (`/?goods/…`, `/?jp/…`, `/?detail/…`) as duplicates of the home page.
- The importer's `CouponMigrator` sets `discount_quantity = 1` on every coupon, although WooCommerce had no such limit.
- Search Console has a domain property verified by a DNS TXT record. The sitemap is https://www.urmotorparts.com/storage/sitemap.xml (variant products excluded), listed in `public/robots.txt`.

## Project Overview

Bagisto 2.4.x - open-source Laravel 12 e-commerce platform. PHP 8.3+, Vue.js 3, Tailwind CSS 3, Vite 5.

## Common Commands

### Development
```bash
composer install                # Install PHP dependencies
php artisan bagisto:install     # Full installation (migrations, seeders, assets)
php artisan serve               # Start PHP dev server
php artisan optimize:clear      # Clear all caches (run after config/code changes)
```

### Testing
```bash
vendor/bin/pest                                         # Run all tests
vendor/bin/pest --testsuite="Admin Feature Test"        # Run a specific test suite
vendor/bin/pest packages/Webkul/Admin/tests/Feature     # Run tests in a directory
vendor/bin/pest --filter="test name"                    # Run a single test by name
```

Test suites defined in `phpunit.xml`: Admin Feature, Core Unit, Customer Unit, DataGrid Unit, Installer Feature, PayU Unit/Feature, Razorpay Unit/Feature, Shop Feature, Stripe Unit/Feature.

Tests use **Pest 3** with package-specific TestCase classes bound in `tests/Pest.php`. Each package's tests live in `packages/Webkul/<Package>/tests/`.

### E2E Tests (Playwright)
E2E tests are run from within each package directory. Each package has its own Playwright config and tests:

**Admin**:
```bash
cd packages/Webkul/Admin
npm install
npx playwright install --with-deps chromium
npx playwright test --config=tests/e2e-pw/playwright.config.ts
```

**Shop**:
```bash
cd packages/Webkul/Shop
npm install
npx playwright install --with-deps chromium
npx playwright test --config=tests/e2e-pw/playwright.config.ts
```

Tests require a running Laravel server (`php artisan serve`) and seeded database. Set `BASE_URL` env var if not using default.

### Code Style
```bash
vendor/bin/pint             # Fix PHP code style (Laravel Pint)
vendor/bin/pint --test      # Check style without fixing
```

### Translations
When adding new translation keys, always provide translations for **all locales** in the package's `Resources/lang/` directory. Verify with:
```bash
php artisan bagisto:translations:check
```

## Architecture

### Modular Package System

All core functionality lives in **`packages/Webkul/`** (~42 packages). Each package is a self-contained Laravel package with its own models, controllers, routes, views, migrations, and service providers.

**Dual registration**: Each package registers in two places:
1. **`bootstrap/providers.php`** - Main ServiceProvider (routes, views, events, config)
2. **`config/concord.php`** - ModuleServiceProvider (Konekt Concord model/enum registration)

### Key Design Patterns

**Repository Pattern**: All database access goes through repositories (`Prettus L5 Repository`). Interfaces in `Contracts/`, implementations in `Repositories/`. Never use models directly for queries in controllers.

**Proxy Pattern**: Models have Proxy classes (e.g., `ProductProxy`, `CategoryProxy`) enabling model substitution without modifying core code. Always reference proxies when type-hinting across packages.

**Event-Driven Extensibility**: The framework fires events at key lifecycle points. Extend behavior via listeners rather than modifying core packages.

### Package Anatomy

```
packages/Webkul/<Package>/src/
├── Config/           # system.php (admin settings), admin-menu.php, acl.php
├── Database/         # Migrations/, Seeders/, Factories/
├── Http/Controllers/ # Separate Admin/ and Shop/ controller directories
├── Models/           # Eloquent models + Proxy classes
├── Repositories/     # Data access layer
├── Contracts/        # Interfaces for models and repositories
├── Resources/
│   ├── views/        # Blade templates (admin/, shop/)
│   ├── lang/         # Localization (translatable strings)
│   └── assets/       # CSS/JS source files
├── Routes/           # admin-routes.php, shop-routes.php, api.php
├── Providers/        # ServiceProvider + ModuleServiceProvider
└── Listeners/        # Event listeners
```

### Frontend Assets

Admin, Shop, and Installer each have independent Vite builds. Run `npm install` and `npm run dev`/`npm run build` from within the respective package directory:
- **Admin**: `packages/Webkul/Admin/` builds to `public/themes/admin/default/build/`
- **Shop**: `packages/Webkul/Shop/` builds to `public/themes/shop/default/build/`
- **Installer**: `packages/Webkul/Installer/`

Vue 3 components are used within Blade templates via `@pushOnce('scripts')` / Blade component slots.

### Naming Conventions

- **Namespace**: `Webkul\<PackageName>` (e.g., `Webkul\Product`)
- **Routes**: Separate `admin-routes.php` and `shop-routes.php` per package
- **Models**: Singular (`Product`, `Category`)
- **Repositories**: `<Model>Repository` (e.g., `ProductRepository`)
- **Controllers**: `<Model>Controller` in `Http/Controllers/Admin/` or `Shop/`

### Adding a New Package

1. Create `packages/Webkul/<Name>/src/` with the standard structure
2. Add PSR-4 namespace to root `composer.json` autoload
3. Register ServiceProvider in `bootstrap/providers.php`
4. Register ModuleServiceProvider in `config/concord.php`
5. Run `composer dump-autoload && php artisan optimize:clear`

Or use: `php artisan package:make Webkul/<Name>` (requires `bagisto/bagisto-package-generator`)

## CI Pipeline

- **pest_tests.yml**: Pest tests on PHP 8.3 + MySQL 8.0
- **pint_tests.yml**: Code style checks with Laravel Pint
- **admin_playwright_tests.yml / shop_playwright_tests.yml**: E2E tests (6 parallel shards)
- **translation_tests.yml**: Translation file validation
