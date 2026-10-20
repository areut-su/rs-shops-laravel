# RS Laravel storefront

Standalone Laravel 13 application for a reseller storefront. It does not use a
database, Redis, or a queue worker. Production defaults are file cache and
sessions with synchronous queues. The browser only calls the same-origin local
API; the reseller token stays in server configuration.

## Local development

Requirements: PHP 8.4+, Composer 2, and the RS checkout for the path-based
`packages/api-client-php` dependency. From this directory:

```sh
cp .env.example .env
php artisan key:generate
composer install
```

Set `STOREFRONT_API_URL` and `STOREFRONT_RESELLER_TOKEN` in `.env`, then run
`php artisan serve`. The local API routes are:

- `GET /api/storefront/v1/catalog`
- `GET /api/storefront/v1/catalog/meta`
- `GET /api/storefront/v1/translations?locale=ru`

Catalog requests accept no query parameters. Translation requests accept only
`locale`, defaulting to `ru`. The server fetches every catalog page using page
numbers and the fixed page size of 100; it never follows URLs supplied by the
upstream. It validates each page and writes products to a private temporary
file, then atomically publishes the complete JSON response only after page
counts, metadata, and product IDs pass validation. The response streams from
that file, so PHP retains one upstream page and a bounded set of product IDs
instead of the full catalog. The local metadata response is cached for one
hour, scoped by a SHA-256 digest of the API URL and token. Catalog files have no
time based expiry: the response includes the core revision as a quoted `ETag`,
and a new complete file is published only after the revision is checked again.
Files for replaced revisions and abandoned temporary files are cleaned during
catalog requests. Translation responses keep their 60 second cache. Upstream
errors and malformed, incomplete, or changing catalog responses return a
generic 503; partial catalogs are never published or served.

## Tests and static analysis

```sh
composer check
```

The feature tests use a fake upstream and do not contact the core API. They also
exercise 7,446 products and 37,692 variants in a subprocess limited to 128 MB.

## Shared hosting deployment

Use PHP 8.4 with `curl`, `json`, and `mbstring`; configure the web server
document root as `public/`. Build the shared widget in the RS checkout with
`npm run build --prefix packages/storefront-price-widget`, then place its
`dist/rs-storefront-widget.js` at `public/assets/rs-storefront-widget.js` and
`theme/default.css` at `public/assets/storefront-theme.css`. The widget page
links that stylesheet; override the `--psw-*` custom properties or use
`::part(...)` selectors there to change the palette without rebuilding the
bundle.
Build the widget, then run
`shops/laravel/scripts/package-shared-hosting.sh [output-directory]` from the RS
checkout. The script assembles the application, widget asset, and PHP
dependencies into a self-contained release directory. The hosting account
does not need Composer or access to the monorepo. The path dependency is copied
into `vendor/` (`symlink: false`).

Set `APP_ENV=production`, `APP_DEBUG=false`, a generated `APP_KEY`,
`CACHE_STORE=file`, `SESSION_DRIVER=file`, and `QUEUE_CONNECTION=sync` in the
hosting environment. Set `STOREFRONT_API_URL` to the HTTPS Satellite API base
URL and provide `STOREFRONT_RESELLER_TOKEN` through server-only environment or
hosting secret configuration. Ensure `storage/` and `bootstrap/cache/` are
writable by PHP. Allow the hosting server's egress IP in the Satellite API
gateway/token configuration and serve the site over HTTPS. Never put the
reseller token in Blade, JavaScript, a URL, or a tracked environment file.

## Local Compose fragment

The optional `compose.yml` is intended to be combined with the root Compose
file. It adds `storefront-laravel` under the `storefront` profile at
`172.31.0.30:8080`, with no host port. The gateway remains the only public
entry point. The root storefront workflow supplies `STOREFRONT_APP_KEY`; the
upstream token is optional for Compose parsing and the API returns a generic
503 until `STOREFRONT_RESELLER_TOKEN` is configured.
