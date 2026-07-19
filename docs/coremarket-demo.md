# CoreMarket Local Demo Environment

## Purpose

This environment provides realistic, fake CoreMarket data for local demonstrations, browser QA, and POS API clients.

It is not a production environment, a client baseline, or a CorePilotOS-managed instance. The protected `coremarket:seed-demo` command is the source of truth for its data.

## Local Environment

- Demo URL: `http://localhost/coremarket-demo`
- Demo database: `coremarket_demo`
- Operations API base URL: `http://localhost/coremarket-demo/api/v2/operations`
- Isolated environment file: `C:\xampp\htdocs\coremarket-demo\.env`

The isolated environment must contain:

```dotenv
DB_DATABASE=coremarket_demo
COREMARKET_RUNTIME_DB_CONNECTION=mysql
```

Do not document or share the local database password. Do not change `C:\xampp\htdocs\coremarket\.env` for demo use.

## Demo Accounts

All credentials below are fake, local-only demo credentials. Never reuse them in production or a client installation.

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@coremarket.demo` | `Demo@2026!` |
| Cashier | `cashier@coremarket.demo` | `Demo@2026!` |
| Inventory manager | `inventory@coremarket.demo` | `Demo@2026!` |
| Accountant | `accountant@coremarket.demo` | `Demo@2026!` |
| Customers | `customer1@coremarket.demo` through `customer10@coremarket.demo` | `Demo@2026!` |

## Operations API

The API base URL is:

```text
http://localhost/coremarket-demo/api/v2/operations
```

Supported demo endpoints include:

- `POST /auth/login`
- `GET /pos/session`
- `GET /pos/search`
- `GET /pos/customers/search`
- `POST /pos/checkout`
- `GET /pos/orders/{order}/receipt`
- `GET /cashboxes`
- `GET /cash-shifts/current`
- `POST /cashboxes/{cashbox}/open-shift`
- `POST /cash-shifts/{shift}/close`

Use the cashier account for POS API authentication. Treat returned access tokens as secrets and delete temporary QA tokens after use.

## Enabled Demo Features

- `pos`
- `cashbox_shifts`
- `loyalty_points`
- `inventory_pro`
- `purchasing_suppliers`
- `returns_management`
- `accounting_lite`
- `accounting_core`

Feature access is isolated through `COREMARKET_RUNTIME_DB_CONNECTION=mysql`, so the local endpoint reads its runtime snapshot from `coremarket_demo` rather than `coremarket_runtime`.

## Seed And Reset

Run the protected command from the CoreMarket project with a temporary PowerShell database override. Always remove the override when finished.

Dry-run:

```powershell
$env:DB_DATABASE="coremarket_demo"
php artisan coremarket:seed-demo --dry-run --with-samples=standard
Remove-Item Env:DB_DATABASE
```

Reset and rebuild the standard demo dataset:

```powershell
$env:DB_DATABASE="coremarket_demo"
php artisan coremarket:seed-demo --reset --apply --confirm-demo-seed --with-samples=standard
Remove-Item Env:DB_DATABASE
```

The command refuses databases that do not end in `_demo` and explicitly refuses `coremarket_runtime`, `coremarket_testing`, `coremarket`, `core_market`, and `syrian_souq`.

## Safety Rules

- Never store demo data in `coremarket_runtime` or `coremarket_testing`.
- Never modify the original CoreMarket `.env` to select the demo database.
- Never point CorePilotOS at the existing `http://localhost/coremarket` URL expecting it to use the demo database.
- Do not add real customer data, credentials, tokens, contact details, or uploaded media.
- Do not place demo rows in the clean client or testing baseline SQL files.
- Flutter POS must use `http://localhost/coremarket-demo/api/v2/operations` when operating against this demo environment.

## Verified QA State

The local environment has been verified for:

- Admin, cashier, inventory manager, and accountant login
- Web POS and Operations POS API sessions
- Product search by name and full demo SKU
- Customer search
- Customer POS checkout with loyalty redemption and earn
- Receipt totals, payment, change, customer, and loyalty summaries
- Operations, inventory, cashbox, returns, loyalty, purchasing, expenses, and accounting screens returning HTTP 200
- Accounting dashboard rendering with zero journal entries
- No negative product stock or loyalty balances

## Demo SQL Export Decision

Do not export `coremarket_demo.sql` now. The protected seed command remains the reproducible source of truth and avoids maintaining another large SQL snapshot.

If rapid offline demonstrations later justify an export, create `database/base/coremarket_demo.sql` only as a private, ignored file after all of the following checks pass:

1. Reset the demo database to a known clean demo state.
2. Apply the standard demo seed once.
3. Verify customer and staff data is entirely fake.
4. Verify `personal_access_tokens` is empty and no sync or API tokens exist.
5. Verify no real email, phone, branding, upload, or transaction data exists.
6. Re-import the dump into a temporary `_demo` database and repeat integrity checks.

The demo SQL file must never replace `coremarket.sql` or `coremarket_test.sql`, and it must not be committed unless repository policy changes explicitly.
