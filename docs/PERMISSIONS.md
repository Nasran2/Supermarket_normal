# Roles and permissions

The central catalog in `app/Support/Permissions.php` defines 150 permissions across 34 groups. The role list, role editor, authorization checks, initial seeder and upgrade migration use the same catalog. All changes are checked by middleware, Form Requests or controllers/services before records are written. Hiding a button is not the authorization boundary.

## Administrator

An active user assigned to the protected **system** Administrator role automatically receives all registered permissions, including new ones. No checkbox selection or re-seeding is needed. Empty permission pivots do not remove Administrator access. Inactive accounts still have no access. The role cannot be renamed, edited or deleted; its name is reserved. Other roles cannot be renamed to Administrator to gain full access.

Only administrators may assign Administrator access or manage Administrator accounts. Non-administrators cannot grant permissions outside their own access or modify roles with greater access. The last active administrator and the current user's own role/active state remain protected.

## Page and action access

Every managed module has separate `view`, `create`, `edit` and `delete` permissions: products, categories, units, multiple-unit presets, suppliers, customers, expense categories, expenses, payment methods, users and roles. Sales, purchases and stock adjustments also have separate CRUD permissions. Financial deletion uses audited reversals and retains transaction history.

Related lookup options needed to enter an authorized transaction remain available inside that transaction form. Viewing a category's products or a customer/supplier's account history is part of that module's View access; links to other modules require their own View access.

| Function | Permission |
| --- | --- |
| POS workspace / create invoice | `pos.access` + `sales.create` |
| Edit invoice in POS | `pos.access` + `sales.edit` |
| Item/invoice discounts | `pos.discount` |
| Override checkout unit price | `pos.override_price` |
| Split payment | `pos.split_payment` |
| Complete unpaid or partially paid invoice | `pos.due_sale` |
| Invoice return / void / delete | `sales.return` / `sales.void` / `sales.delete` |
| Collect invoice due | `sales.collect_payment` |
| Print receipt | `sales.receipt` plus invoice view or own-sale creation access |
| Collect customer account payment | `customers.collect_payment` |
| Customer ledger / download | `customers.ledger` / `customers.export` |
| Supplier ledger / download | `suppliers.ledger` / `suppliers.export` plus `suppliers.view` |
| Pay supplier / refund / previous balance | `purchases.pay` / `purchases.refund` / `purchases.set_balance` |
| Void / delete purchase | `purchases.void` / `purchases.delete` |
| Shipping and other purchase charges | `purchases.manage_charges` |
| Product costs / stock history / prices | `products.view_cost` / `products.view_history` / `products.manage_prices` |
| Incoming delivery prices | `purchases.manage_prices` |
| Default unit | `units.set_default` |
| View own / all register history | `register.view` / `register.view_all` |
| Open / close / manual cash movement | `register.open` / `register.close` / `register.movement` |
| Business / POS / receipt / stock / system settings | `settings.business` / `settings.pos` / `settings.receipt` / `settings.stock` / `settings.system` |

View own register or Close register permits the current-shift summary popup; Close register authorizes the closing operation. Own profile editing and sign-out remain available to every authenticated account.

## Dashboard cards

`dashboard.view` opens the page. Each card has its own switch:

- `dashboard.sales`, `dashboard.profit`, `dashboard.expenses`, `dashboard.transactions`
- `dashboard.collections`, `dashboard.sales_overview`, `dashboard.register`
- `dashboard.recent_sales`, `dashboard.low_stock`, `dashboard.top_products`, `dashboard.recent_expenses`

Profit also requires `products.view_cost`. Unselected cards are omitted and their corresponding lists/chart data are not queried. Report links still require report viewing access. A dashboard with no selected cards shows a clear empty state.

## Reports and downloads

Every report has its existing `reports.{report}` View permission plus `reports.{report}.pdf` for PDF download. Tabular reports also have `reports.{report}.export` for CSV. Each report, including Returns and Due collections, has its own access. Downloads require both the View and matching download permission. Profit reports also require product cost access. Ledger exports similarly require ledger access and their Export permission.

## Upgrade and defaults

The `2026_10_09_100000_expand_role_permissions` migration preserves existing role access by copying old shared permissions into the newly introduced controls **once**, when each permission is created. Later revocations are respected: re-seeding does not restore a removed permission on custom, Manager or Cashier roles. Administrator remains automatic.

New Manager roles receive all catalog permissions except Users and Roles. New Cashier roles retain the previous checkout/dashboard workflow, including customer creation, discounts, split payments, due sales, product history and read-only stock adjustment history. Cashiers have no cost, settings, invoice reversal or team-management access. These default assignments can be changed in the role editor.

## Editor

Search modules or actions, choose individual permissions, or select a module. Counts show selected and available access. During search, Select visible and Clear visible affect only matching controls. Dashboard controls appear first with readable card names and descriptions. Unsaved checkbox changes do not affect accounts until Create role or Save changes is submitted.

## Verification

Feature coverage includes automatic Administrator access with an empty pivot, inactive accounts, independent dashboard cards and CRUD, ledger/payment authorization, per-report PDF/CSV access, role escalation protection, POS special actions, and one-time legacy migration/revocation behavior. HTTP checks use isolated test databases; browser layout previews render the actual templates without saving role grants. The roles overview and searchable Dashboard controls have saved previews in `docs/screenshots/roles-overview.png` and `docs/screenshots/permissions-editor.png`.

Validation on 9 October 2026: all 17 role/permission feature tests and all 20 JavaScript tests pass. The production asset build and PHP lint of 119 compiled Blade templates pass. The full feature suite reports 191 passing tests and 28 payment-fee failures; the same 28 failures were reproduced against unchanged HEAD before this permissions work (174 passing tests). Those older fee-rule expectations are outside this access-control change.
