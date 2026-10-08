# Due checkout and product stock history

## Cashier workflow

1. Add products and open **Take payment**. Cash starts at the full bill amount and is selected for immediate typing.
2. For a partial cash payment, enter the amount actually received. **Complete with due** shows the remaining balance. For no payment, choose **No payment · leave bill due**.
3. When no named customer is selected, a popup asks you to select an active customer or **Add new customer**. Saving a new customer selects them and resumes checkout. A due sale cannot continue as walk-in; a fully paid sale can.
4. Successful checkout clears the cart and prints the received amount and balance due. **Pay due** on the saved invoice collects later payments.

Split payments may leave part of the bill unallocated. Card, QR and transfer tenders must equal their allocated payable amounts; use a smaller allocation for a partial noncash payment. Fees apply to allocated shares. Fully unpaid checkout saves no payment or fee rows. Customer balance includes remaining opening debt and active unpaid invoices, adjusted for returns and later collections. Opening debt is separate from each invoice.

The ordinary **Complete sale** path still requires full payment. Invoice revisions also require full revised payments. Register requirements, stock checks, signed quote validation and retry protection remain in place.

## Product history

Each product profile includes a paginated **Stock movement history** with date/time, movement type, bill/reference, signed primary-unit quantity, stock after and cashier. Sale, reversal and revision references link to their invoice. Returns link to the original invoice and retain their return reference. Invoice links require sales-view permission; product viewers can still read movement history.

## Focused verification

The due checkout and movement tests pass on SQLite and MySQL/MariaDB: 8 tests / 101 assertions. Related checkout, customer, split-payment, revision and aftercare checks also passed earlier (45 tests / 547 assertions on SQLite). The full suite was not run.

Isolated browser QA saved an 810.00 bill with 10.00 received and 800.00 due after adding a new customer, and a fully unpaid 180.00 bill against that customer. It also verified the paid walk-in prompt and catalogue loading after applying pending migrations to the QA database. Product history invoice navigation was checked on the main installation without creating test financial records there.

[Customer popup](screenshots/due-customer-selection.jpg), [product history](screenshots/product-stock-history.jpg).
