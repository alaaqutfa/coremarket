# CoreMarket Document and Label Designer Plan

## Scope

This document records the Step 60 audit and the proposed foundation for a future no-code document designer. Step 60 does not implement a designer, change PDF behavior, or copy code from another application.

## Existing CoreMarket Foundation

- Sales invoices already render through the installed mPDF integration.
- Purchase Order, Purchase Receipt, and Supplier Statement PDFs use `OperationsPdfService` and dedicated Blade templates.
- Web POS has an HTML receipt template, while Flutter POS owns its local 58mm/80mm receipt formatting and Windows printing foundation.
- Store name and logo settings exist, but layout, colors, item columns, and paper profiles are not managed through a unified template model.
- No reusable price-label or barcode-label designer exists in the current CoreMarket repository.

## Perfex Reference Audit

Perfex was inspected as a functional reference only. No PHP, JavaScript, HTML, styles, database structure, or assets were copied.

Useful concepts observed:

- PDF logo, logo width, font, and font-size settings are independent options.
- Document numbers are formatted through a dedicated numbering helper rather than being embedded in templates.
- Item tables support configurable columns, taxes, units, descriptions, and custom fields.
- Invoice and statement documents use separate renderers while sharing company identity and money-formatting concepts.
- Customer statements have explicit date ranges, opening/closing balances, and document-specific layouts.

These ideas should be reimplemented in CoreMarket conventions using Laravel, Blade, the existing mPDF adapter, existing receipt payloads, and current permission/feature services.

## Future Modules

### Invoice Designer

- A4 sales and purchase invoice templates.
- Store/client/branch template assignment.
- Logo, store name, accent color, header, footer, terms, and signature blocks.
- Configurable item columns such as SKU, barcode, family, quantity, unit price, tax, and totals.
- Existing stored snapshots remain the source for historical prices and taxes.

### Receipt Designer

- 80mm and 58mm paper profiles.
- Header/footer text, logo option, cashier, cashbox, loyalty, payment, and change fields.
- Shared semantic receipt payload for web and Flutter, without making either client the source of truth.

### Price Label Designer

- Common label sizes and custom width/height.
- Product name, regular price, sale price, currency, SKU, barcode, family, and store identity.
- Bulk selection and print-sheet layout.

### Barcode Label Designer

- Barcode symbology and human-readable value options.
- Variant SKU/barcode support.
- Quantity-per-product and label-printer paper profiles.
- No automatic barcode generation without explicit validation and uniqueness checks.

## Proposed Data Model

A later step can add a small additive template foundation:

- `document_templates`: type, name, scope, paper profile, layout JSON, style JSON, active/default flags.
- `document_template_assignments`: template, store/client, optional branch, document context.
- Versioned template snapshots for official documents when layout immutability is required.

The exact schema must be audited against existing settings before migration. Templates should reference safe asset IDs rather than arbitrary filesystem paths.

## Editing Experience

- Web-based no-code editor with predefined safe blocks.
- Drag/reorder blocks, enable/disable fields, and configure alignment and widths.
- Server-rendered preview using real sanitized sample payloads.
- No arbitrary PHP, Blade, JavaScript, or remote HTML in client templates.
- Draft, preview, publish, duplicate, and restore-last-published workflow.

## Roles and Plans

- `designer_content` may edit approved visual templates when the plan enables document design.
- Store administrators may select/publish permitted templates.
- Platform support can maintain base templates and troubleshoot client layouts.
- Financial fields and supplier statements still require their existing accounting/purchasing permissions.

## Hardware Targets

- A4 invoice printer through browser/system printing.
- Windows thermal receipt printer at 80mm/58mm.
- Dedicated price-label and barcode-label printers.
- Cash drawer control remains a separate hardware capability.

## Safety and Delivery Order

1. Define template payload contracts and safe block schema.
2. Add preview-only template storage and versioning.
3. Integrate A4 purchase/sales PDFs.
4. Integrate web and Flutter receipt profiles.
5. Add price/barcode label layouts and printer QA.
6. Add per-branch assignment only after branch-specific policies are implemented.

No email/WhatsApp sending, printer driver integration, or branch-specific inventory/pricing is part of Step 60.
