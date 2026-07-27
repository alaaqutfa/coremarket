# CoreMarket Pilot Hardware Readiness

## Recommended Hardware

- Windows 11 Pro POS laptop, mini PC, or all-in-one with Intel Core i3/Ryzen 3 or better, 8 GB RAM, 256 GB SSD, Ethernet, Wi-Fi, and at least three USB ports.
- USB barcode scanner configured as keyboard input with an Enter suffix.
- 58 mm or 80 mm thermal printer installed through the Windows printer driver.
- Cash drawer connected through a supported printer kick port. Drawer opening is not implemented yet.
- Stable local network and internet connection. CoreMarket POS is online-first.
- UPS for the POS device, router, and printer is strongly recommended.

## Workstations

| Station | Recommended equipment | CoreMarket role |
| --- | --- | --- |
| Cashier | Dedicated Windows POS, barcode scanner, thermal printer, optional drawer, UPS | `cashier` |
| Accountant | Windows laptop/desktop, reliable browser, PDF reader/printer | `accountant` |
| Warehouse | Rugged laptop or desktop, USB/wireless keyboard scanner, stable Wi-Fi | `warehouse_keeper` |
| Manager | Secured laptop with MFA-ready email and private network access | `owner_general_manager` |
| Data entry / purchasing | Full keyboard, large monitor, barcode scanner, document scanner optional | `data_entry_purchasing` |

## Pilot Checks

- Confirm each staff member signs in with an individual account.
- Verify the scanner enters a complete SKU/barcode and sends Enter once.
- Print a 58 mm and 80 mm test receipt before the pilot.
- Confirm fallback receipt preview/copy works when the printer is unavailable.
- Verify the demo/client API URL and network before opening a cashier shift.
- Never share the platform `Super Admin` account with client staff.
