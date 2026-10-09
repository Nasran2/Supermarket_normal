# Category products, account histories and report downloads

Category View lists every product attached through the product-category membership table, including products in multiple categories. Name/SKU/barcode search, active-status filters and pagination are available; product names and View actions open their details.

Supplier lists show confirmed outstanding dues and flag older purchases whose payment balance has not been recorded. Supplier View includes contact details, current dues, purchase history with invoice links, payment status and a chronological ledger. Date filters apply to both histories and the PDF/CSV downloads. The current due card always shows the current balance, independent of the selected history dates.

The ledger carries forward activity before the From date as the opening balance. Purchases and supplier refunds increase the balance owed; payments, recorded historical payments and voids reduce it. Audited total corrections are recorded as revisions. Unrecorded purchase payment balances remain visible with zero impact until recorded, rather than being assumed paid or unpaid. Supplier payments do not create duplicate expenses. Downloaded ledgers include every matching entry, independently of screen pagination.

Customer list balance filters and overview totals subtract paid opening balances. Collect Payment appears only in Actions, only for customers with an outstanding balance and users with sales editing permission. It opens the existing customer payment dialog.

Expense create/edit has a plus button beside Category. The popup saves a category through the existing validated, permission-controlled resource endpoint, automatically selects it and preserves the unsaved expense fields. Duplicate names and request failures appear inside the dialog.

All 16 reports, including Profit & loss and Audit log, offer an actual PDF attachment. Report permissions and cost visibility are retained. PDFs use Business settings for company name, address, phone, email, currency and supported local logo images. They include report-specific columns or statements, matching filter labels, summary totals, preparation metadata, repeated table headings and page numbers. Stock exports are current snapshots, rather than historical reconstructed balances. CSV exports remain available for tabular reports.

Exports include all matching records. Standard reports use a dedicated print template; large exports draw tables directly on PDF pages and read database rows in chunks. Large cell text is wrapped and split across pages without dropping content. Audit PDFs show before/after snapshots as document sections instead of squeezing JSON into table columns. Remote PDF resources and embedded scripts are disabled.

Validation: 198 feature/unit tests passed (1,949 assertions), including account balance calculations, category memberships, permissions, inline categories, all 16 PDF routes and a 650-row Unicode/long-text export. PDF text inspection verified company details, all 28 stock fixture rows and all 650 large-export entries. Browser QA verified account date filters, inline category draft preservation and a 10,057-product export; every product SKU was found in the downloaded PDF. Rendered PDFs were inspected for layout and pagination. Test writes used the isolated QA database.

The application now requires `dompdf/dompdf` via Composer. Run `composer install` when updating another installation.
