# CostSync

CostSync is a Laravel + Filament admin panel that keeps product cost data in sync with Odoo. It automates sale price calculations, logs every sync attempt, and provides operators with filtering, bulk updates, and CSV exports.

## 1. Requirements

- PHP 8.2+ with `intl` extension enabled
- Composer
- SQLite (bundled with PHP)
- Node.js 18+ (only required if you want to compile frontend assets)

## 2. Quick Start

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan make:filament-user --name="Admin" --email=admin@example.com --password=password
php artisan serve
```

Open the Filament panel at `http://127.0.0.1:8000/admin` and sign in with the user you created. The seeders load a small catalog so you can experiment immediately.

## 3. Environment

Key environment variables for the Odoo integration live in `.env`:

```
APP_CURRENCY=USD

ODOO_BASE_URL=https://odoo.example.com
ODOO_DB=mydb
ODOO_USERNAME=demo
ODOO_PASSWORD=demo
ODOO_API_KEY=
ODOO_SIMULATE=true
```

Leave `ODOO_SIMULATE=true` to work with the bundled fake client. Switch it to `false` (and set `ODOO_API_KEY` or `ODOO_PASSWORD`) to use the real Odoo JSON-RPC client (`OdooRestClient`). When an API key is provided it takes precedence over the password.

### Odoo setup checklist

1. **Create / select an integration user** in Odoo (`Settings → Users`) and give it enough Inventory permissions to edit product costs (typically Inventory > Administrator or a custom role with cost access).
2. **Generate an API key** for that user from the Odoo profile page and place it in `.env` as `ODOO_API_KEY` (a password fallback is supported but the API key is preferred).
3. **Ensure product SKUs match**: every product you want to sync must exist in Odoo with the same `Internal Reference` (`default_code`) as the `sku` stored in CostSync.
4. Optional but recommended: create a dedicated `Product` category / company context if you operate multiple companies and adjust the user permissions accordingly.
5. Test by editing a product cost in the Filament panel; the corresponding product in Odoo should show the updated **Cost** and, if provided, **Sales Price**.

## 4. Features

- **Products CRUD** with automatic sale price calculation (`cost * (1 + markup%)`).
- **Filament filters** for SKU contains, cost range, and last update window.
- **Bulk cost adjustments** (increase/decrease by percentage) that fire a sync per product.
- **Odoo sync pipeline** via `SyncProductCostToOdoo` job, `OdooSyncService`, and a pluggable client (`OdooFakeClient` or `OdooRestClient`) that updates both `standard_price` (cost) and `list_price` (sale) when connected to a live Odoo instance.
- **Sync logs** for every attempt (success or failure) with payload, response, status badge, and JSON drill-down.
- **CSV export** endpoint that respects the same filters (`/products/export`).
- **Sample data** seeded through `ProductSeeder` with different costs and markups.

## 5. Architecture Overview

| Layer | Responsibility |
| ----- | -------------- |
| `App\Domain\Products\Product` | Domain model; recalculates sale price & dispatches sync job when cost/markup/currency changes. |
| `SyncProductCostToOdoo` job | Loads the product and invokes `OdooSyncService` synchronously (`dispatchSync()` by default). |
| `OdooSyncService` | Normalises payloads, calls the configured client, and writes to `SyncLog`. |
| `OdooFakeClient` | Simulates network latency and 10% random failures for realistic demos. |
| `ProductResource` | Filament resource for CRUD, filters, and bulk actions. |
| `SyncLogResource` | Read-only Filament resource with badges, JSON previews, and auto-refresh. |
| `ProductsController@export` | Streams filtered CSV output using native `fputcsv`. |

Switch between fake and real clients through the service container binding in `AppServiceProvider`:

```php
$this->app->bind(OdooClientInterface::class, fn () => config('services.odoo.simulate')
    ? new OdooFakeClient(config('services.odoo'))
    : new OdooRestClient(config('services.odoo')));
```

## 6. Usage Notes

1. **Editing a product** recalculates the sale price instantly and queues an Odoo sync attempt (synchronously in this iteration).
2. **Bulk actions** on the product list ask for a percentage, update costs, and trigger a sync per record.
3. **Sync logs** refresh every 10 seconds; click into a row to inspect payload/response JSON.
4. **CSV export** is available at `/products/export`. Pass `sku`, `cost_min`, `cost_max`, `from`, and `to` as query params to reuse the table filters.
5. **Queues** currently run synchronously via `dispatchSync()`. Flip to real queuing by swapping `dispatchSync` with `dispatch` and running `php artisan queue:work`.

## 7. Demo Video Script (2–5 min)

1. Log into `/admin` with the Filament user.  
2. Show the seeded product grid and apply SKU & cost filters.  
3. Edit a product, change the cost, save, and point at the success sync log entry.  
4. Run a bulk increase, then highlight the burst of log entries.  
5. Download `/products/export` and open the CSV.  
6. Give a quick tour of `OdooSyncService`, the job, and the fake client.

## 8. Going Further

- Replace `dispatchSync()` with async queues and configure retries/backoff.
- Add dedicated unit/integration tests for the service layer and export endpoint.
- Implement idempotency or outbox patterns before calling real Odoo.
- Extend currency handling with FX conversions and multi-currency pricing.
- Harden the REST client with proper authentication & error parsing once the real API is available.

Enjoy building! Let me know if you need the demo video template or further integrations.
