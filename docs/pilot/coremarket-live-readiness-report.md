# CoreMarket Live Readiness Report

## Flutter Windows POS Status

Status date: 2026-07-30

- Backend baseline: `9cdbd556ccb2847c6afd6746cf1c66073a5511b5`
- POS API compatibility: `ac8860a5b358982fce6800f60e33aad24ff907d8`
- Flutter baseline audited: `9beb5ffb6e8e85f1837c3d4c1779f5a9ec43bdce`
- Flutter analyze: passed.
- Flutter tests: 38 passed; 2 credentialed live-demo tests skipped by default.
- Windows release build: passed.
- Pilot status: Go for supervised Windows retail/electronics pilot.

Supported in the Windows client:

- Cash sale, cashbox shift, receipt, and existing cash offline queue.
- Server-side branch pricing, customer Price Lists, and branch stock checks.
- Pay on Account with server credit decision and unpaid receipt status.
- Serial/IMEI selection and receipt identity display.

Web operations UI remains required for Sales Returns, cash refunds, customer
credit notes, warranty lookup, and warranty claims because dedicated Operations
API endpoints do not yet exist. Pay on Account and serialized sales are
online-only. No deployment or demo synchronization was performed.
