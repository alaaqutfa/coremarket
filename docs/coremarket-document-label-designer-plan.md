# CoreMarket Document and Label Designer Plan

## Scope

This document records the Step 60 audit and the safe foundation implemented in Step 61. Perfex remains a functional reference only; no code, schema, styles, or assets were copied.

## Implemented in Step 61

- A `document_templates` table stores safe, structured display settings rather than executable templates.
- Seven idempotent presets cover Purchase Order A4, Purchase Receipt A4, Supplier Statement A4, POS Receipt 80mm/58mm, Price Label, and Barcode Label.
- Web pages allow authorized staff to list, create, edit, preview, activate, deactivate, and select a default template.
- Purchase Order, Purchase Receipt, and Supplier Statement PDFs resolve their active default template and fall back to the existing safe layout if the table or template is unavailable.
- Label selection generates PDF previews for selected products. Barcode values are human-readable text for now because no stable 1D barcode renderer is currently part of the application.
- `document_templates.view`, `document_templates.manage`, and `document_templates.preview` use the existing Spatie permission system.

## Safe Settings Contract

Allowed settings include logo visibility and position, validated hexadecimal colors, bounded font size, store/party visibility, SKU/barcode/tax/discount/family visibility, plain-text footer content, allowlisted columns, and bounded label-grid values.

Users cannot enter raw HTML, PHP, Blade, JavaScript, remote templates, filesystem paths, or executable expressions. Footer text is escaped by Blade and rejects HTML or executable markers. Colors, columns, dimensions, margins, and paper profiles are validated server-side.

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

## Data Model

- `document_templates`: type, name/code, paper profile, dimensions/margins, safe settings JSON, and active/default flags.
- Per-store/branch assignments and published version snapshots remain future work.
- Existing store logo settings are resolved server-side; templates do not accept arbitrary asset paths.

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

No email/WhatsApp sending, printer driver integration, native receipt/label hardware integration, or branch-specific assignment is part of Step 61.

## Future Work

- Advanced drag-and-drop blocks with a fixed safe component catalog.
- Customer sales invoice designer expansion.
- Template publishing/version snapshots for immutable historical output.
- Native receipt and label printer integration.
- Per-client/store/branch template assignment.
- Email and WhatsApp delivery after explicit security and audit work.
