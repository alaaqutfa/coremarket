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
     --admin-email="admin@example.com" `
     --password="use-a-secure-temporary-password" `
     --domain="store.example.com" `
     --plan="enterprise" `
     --write-env `
     --enable-enterprise
   ```

   Use `--email`/`--pass` only as aliases. Use `--force` only to deliberately
   replace an existing Admin password, rotate an existing sync token, or
   convert a reviewed existing non-admin account.

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
12. [ ] Connect CorePilotOS later using the `COREPILOT_SYNC_TOKEN` from the
        client `.env`. Never send the token in screenshots, chat, or logs.

## Acceptance

- [ ] `coremarket:guard-database` passes.
- [ ] Admin login and Operations dashboard load.
- [ ] Roles/permissions match the approved staff matrix.
- [ ] `COREPILOT_SYNC_TOKEN` exists in `.env` and is absent from Git/logs.
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
