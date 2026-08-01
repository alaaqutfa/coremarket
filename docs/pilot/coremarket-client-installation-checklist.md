# CoreMarket Client Installation Checklist

## Before Installation

- [ ] Record the approved CoreMarket commit/tag and client plan.
- [ ] Create a dedicated client database; never reuse runtime, demo, or testing.
- [ ] Take a server/file/database backup if replacing an existing installation.
- [ ] Confirm PHP, MySQL, Composer, storage permissions, and the web root.

## Installation

1. [ ] Create the empty client database.
2. [ ] Import `database/base/coremarket.sql` into that exact database.
3. [ ] Configure `.env` with the client `APP_URL`, database connection, mail,
       cache/session, and other environment-specific values. Never commit it.
4. [ ] Confirm the configured and selected database, then run:

   ```powershell
   php artisan coremarket:guard-database
   ```

5. [ ] Run the first-client setup command with a secure handoff password:

   ```powershell
   php artisan coremarket:client-setup `
     --project="Client Store" `
     --support-admin-email="support@corepilot-os.com" `
     --support-admin-password="use-a-secure-support-password" `
     --client-admin-email="owner@example.com" `
     --client-admin-password="use-a-secure-client-password" `
     --domain="store.example.com" `
     --plan="enterprise" `
     --write-env `
     --production-env `
     --enable-enterprise
   ```

   Support Admin is owned by CorePilot for setup and troubleshooting and
   normally receives `owner_general_manager`. Client Admin is owned by the
   customer and normally receives `store_admin`. Legacy `--admin-email` and
   `--email` are Support Admin aliases only. Use `--force` only to deliberately
   replace a reviewed password, rotate both sync tokens, or convert a reviewed
   existing non-admin account.

6. [ ] Clear caches:

   ```powershell
   php artisan optimize:clear
   ```

7. [ ] Create the public storage link if it does not already exist:

   ```powershell
   php artisan storage:link
   ```

8. [ ] Run Branch Inventory dry-run only:

   ```powershell
   php artisan coremarket:branch-inventory-initialize --dry-run
   ```

   Do not apply until products/legacy stock, the default branch, proposed row
   count, and differences have been reviewed. Take another DB backup before
   the confirmed apply command.

9. [ ] Open the reported login URL and sign in with the first Admin.
10. [ ] Change the temporary Admin password immediately and store it through
        the approved client credential process.
11. [ ] Configure products, branches, staff/users, stock, prices, suppliers,
        customer accounts, documents, and operational policies.
12. [ ] Run `php artisan coremarket:receiver-diagnostics`, then connect
        CorePilotOS using the full `COREPILOT_RUNTIME_SYNC_TOKEN` from the
        client `.env`. Never send the token in screenshots, chat, or logs.

## Acceptance

- [ ] `coremarket:guard-database` passes.
- [ ] Admin login and Operations dashboard load.
- [ ] Roles/permissions match the approved staff matrix.
- [ ] `COREPILOT_RUNTIME_SYNC_TOKEN` exists in `.env` and is absent from
      Git/logs.
- [ ] `COREMARKET_RUNTIME_DB_CONNECTION=mysql` is configured for the normal
      standalone single-database installation.
- [ ] Enterprise flags match the purchased plan.
- [ ] Default branch is correct before Branch Inventory apply.
- [ ] Products start at documented stock; direct stock edits are blocked when
      strict inventory is enabled.
- [ ] Cashbox, POS, purchasing, returns, documents, and reports pass smoke QA.
- [ ] Backup and rollback locations are documented.

## Prohibited Shortcuts

- Do not run `migrate:fresh` or `db:wipe` on a client database.
- Do not import a baseline over an existing client database.
- Do not run demo/QA/testing restore commands on the client database.
- Do not commit `.env`, backup files, SQL dumps, logs, or generated builds.
- Do not enable Branch Inventory apply before a clean reviewed dry-run.

## CorePilotOS Runtime Receiver Setup

The canonical receiver token key is `COREPILOT_RUNTIME_SYNC_TOKEN`. The older
`COREPILOT_SYNC_TOKEN` is retained only for backward compatibility; CoreMarket's
receiver middleware reads the canonical key. This token is different from the
CoreMarket add-on request token and must not be substituted with
`COREPILOT_ADDON_REQUEST_TOKEN`.

For a standalone client installation where the client database is the default
Laravel `mysql` connection, configure:

```dotenv
COREMARKET_RUNTIME_DB_CONNECTION=mysql
```

After `php artisan optimize:clear`, run:

```powershell
php artisan coremarket:receiver-diagnostics
```

The command must report the intended default/runtime database and a present
`business_settings` table. It displays only a masked token. In CorePilotOS open
the client Instance Connection, choose **Replace API token**, and paste the full
`COREPILOT_RUNTIME_SYNC_TOKEN` from the protected server `.env`. Then run **Test
CoreMarket Receiver**.

Receiver contract:

- Header: `X-CorePilot-Sync-Token`
- Preview: `/api/corepilot/runtime-snapshot/preview`
- Apply: `/api/corepilot/runtime-snapshot/apply`

Common errors:

- `403 Forbidden`: CorePilotOS token and `COREPILOT_RUNTIME_SYNC_TOKEN` differ.
- Runtime storage unavailable or unexpected `coremarket_runtime`: set
  `COREMARKET_RUNTIME_DB_CONNECTION=mysql`, then clear configuration cache.
- `APP_URL` contains localhost: set the real HTTPS URL, or rerun setup with
  `--write-env --production-env` after backup.
- `APP_ENV=local` on a public domain: explicitly use `--production-env`; setup
  never changes production environment values implicitly.
