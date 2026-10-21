# Optional cheque management

Cheque management records customer cheques, business cheques paid to suppliers, and customer cheques endorsed to suppliers. It extends the existing payment records rather than creating a second payment accounting system. Each physical cheque has one ID, a date, amount, number, bank and audited lifecycle.

## Enable and disable

On installation or upgrade:

```sh
php artisan migrate --force
npm run build
```

Set the feature in `.env`:

```dotenv
CHEQUE=true
```

Then run:

```sh
php artisan optimize:clear
```

If the installation caches configuration in production, run `php artisan config:cache` afterwards. All application code reads `config('features.cheque')`; the environment is read only by `config/features.php`.

With `CHEQUE=false`, cheque payment choices, entry fields, management menus, dashboard alerts, settings, report links, account Hold/history cards, cheque audit actions and role permission entries disappear. Direct cheque routes, exports and attachment downloads return 404, including for Administrator. Forged disabled permission assignments are rejected. Existing role assignments are preserved when editing other permissions. Turning the feature back on restores access to the saved cheques, payment links, statuses, events, attachments and balances. No financial records are deleted or reclassified by toggling the feature. Existing stock, revenue and normal Cash/Card/QR payments remain unchanged.

The migration adds tables and nullable links without reconstructing historic cheques or changing existing transaction amounts. Use a separate disposable database for tests. For an existing `DEMO=true` installation, deliberately recreate its demo baseline after the schema upgrade, following the existing demo deployment instructions; do not reuse a baseline from the older schema.

## Due, Hold and clearance

Cheque Hold means payments waiting for cheque clearance. It is separate from normal outstanding Due.

A customer owing Rs.25,000 gives a Rs.10,000 cheque:

| State | Due | Cheque Hold | Receivable exposure |
| --- | ---: | ---: | ---: |
| Before receipt | 25,000 | 0 | 25,000 |
| Pending | 15,000 | 10,000 | 25,000 |
| Passed | 15,000 | 0 | 15,000 |
| Returned instead | 25,000 | 0 | 25,000 |

The same distinction applies to supplier balances. Do not count the original Due plus Hold before reducing Due. Hold values come from pending instruments, not editable balance fields. Pending cheque allocations are retained in payment history; returned/cancelled allocations no longer offset Due. Opening-balance allocations are reversed exactly when the cheque returns.

Sales revenue, purchase value, COGS and profit do not change when a cheque passes or returns. Cash drawer totals never change because of a cheque. Money collection reports use clearance events once at the actual passing date, rather than treating the original cheque receipt as cash. This application has bank account references, not a bank balance ledger; no bank-account balance or fake register cash movement is created.

## Receive customer cheques

In POS choose a named customer and the Cheque payment method. Enter each amount, actual cheque date, number and bank. Use **Add another cheque** for multiple dates/instruments. The total of the entries must equal that payment's allocated share. The normal split payment mode supports cash/card plus cheque plus remaining customer Due. Example: a Rs.30,000 bill with Rs.5,000 cash, Rs.20,000 in cheques and Rs.5,000 Due adds only Rs.5,000 to drawer cash.

If Cheque is selected for a walk-in customer, a customer popup lets the cashier select an existing customer or, with `customers.create`, add a new one. The popup returns to payment with cheque details preserved; selecting/creating a customer does not automatically complete a cheque sale.

Each cheque becomes a separate record and invoice payment. A Rs.25,000 bill can receive cheques of Rs.10,000, Rs.10,000 and Rs.5,000 with independent dates/statuses. The receipt shows each cheque's date and number in small text below its payment label, including single-cheque bills, along with the number of cheques, original cheque amount, settled payment and current Hold.

Existing invoice **Pay due** and Customer **Collect Payment** accept multiple cheques too. Customer payments can allocate automatically to opening Due then oldest accessible invoices, or use the existing manual allocation controls. One instrument may cover several allocations for one customer. On return, only that instrument's allocations are reversed.

**Cheques → Record cheques** can receive against existing customer Due or pay an outstanding supplier purchase. It does not create an unallocated customer credit. Receiving requires `cheques.receive` as well as the existing transaction permission. The standalone form also requires `cheques.create`.

## Own cheques and supplier payments

Select Cheque while creating a purchase or using **Pay supplier due**. Each entry can use **Own cheque** or **Customer / third-party cheque**. Several own cheques, several customer cheques, or a mixture are supported. Enter the details for own cheques; optionally choose a business bank account to fill its bank, account, drawer and branch.

Only the full payable amount may be allocated. Excess cheque amounts are blocked; cheques do not produce cash change. Pending payments lower supplier Due and increase supplier Hold. Passed cheques remove Hold. Returned/cancelled own cheques remove Hold and restore the original purchase Due.

Settings → Cheques provides simple business bank account references. Accounts can be added and activated/deactivated without deleting history. Own issuance needs `cheques.issue`; third-party selection needs `cheques.transfer`. The existing purchase payment/create permissions still apply.

## Third-party customer → supplier flow

Only available pending customer cheques can be selected. A cheque already transferred, passed, returned or cancelled cannot be reused. The selected physical instrument transfers in full to one supplier/purchase; splitting it between suppliers and using it above the purchase balance are blocked.

The original cheque changes to Third-Party / Transferred and displays **Customer → Supplier**. It keeps its customer and gains a supplier/purchase link. Customer Hold remains and supplier Hold increases, both linked to the same cheque ID.

Passing settles both Holds together. Returning removes both Holds and restores both Dues in one database transaction. Third-party settlement never creates business bank inflow/outflow.

An authorized **Reverse transfer** action requires a reason and confirmation that the physical cheque has been retrieved from the supplier. It restores the supplier Due, cancels that supplier allocation and makes the same customer cheque available again; the customer Hold stays pending. Old cancelled supplier allocations never reactivate if the instrument is subsequently transferred/passed. A transferred cheque cannot be casually cancelled; retrieve/reverse its transfer first, or use Returned for a bounced instrument.

## Dates, dashboard and actions

The default alert/action window is three calendar days before each cheque date, measured in the business timezone. For an 18 October cheque, ordinary Pass/Return actions start on 15 October. Settings can change the window from 0–90 days. Both button visibility and backend action checks enforce it. `cheques.override_action_date` permits an explicit early action; grant it sparingly. Administrator has it automatically.

The dashboard shows a red Cheques Due Soon alert immediately below Collections, with overdue instruments first, then today and future dates inside the configured window. Long-dated cheques do not appear. Instruments never pass automatically. Passing, returning and cancelling require confirmation. Return reasons are optional; cancellation and transfer-reversal reasons remain required. Terminal settlements cannot run twice or be edited through normal cheque forms.

## Edit and resolve returns

**Edit** is available on pending and transferred cheques. Correct the number, cheque date, bank, branch, drawer, account number, reference and notes. Each save creates an audited timeline event. Duplicate bank/account/number combinations and stale edits are rejected. Amounts, parties and document links remain tied to the original payment: return or cancel and record a replacement when those need to change.

**Mark Returned** opens a popup with an optional reason and three choices:

- **Leave balance due** reverses the original cheque hold and restores its balance to Due.
- **Get / issue replacement cheque(s)** records one or several new instruments, with independent dates and amounts. Their combined value cannot exceed the remaining returned amount. Partial replacement leaves the difference due, and replacements remain on Hold until passed.
- **Record cash / bank / card payment** pays all or part of the restored balance through an active payment method. Supplier cash can optionally be taken from the current register, using the existing purchase-payment controls.

Returned cheques offer **Resolve return** for later replacements or payments. The original instrument stays Returned, and its detail page links each replacement and lists every resolution. A replacement's detail page links back to the original. For a third-party return, both accounts become due; choose the customer or supplier side for each resolution, then handle the other side separately.

Recoveries apply only to the original opening-balance/invoice/purchase allocations. They cannot shift money to unrelated debt if the original balance has already been paid. Invalid submissions roll back the return and all new payments together. Repeated submissions with the same token and details do not create duplicate payments.

The additive `2026_10_14_010000_add_cheque_return_resolutions.php` migration creates resolution history and replacement links, extends existing demo baselines with the new empty tables, and installs the new permissions. Run `php artisan migrate --force` on other installations, then rebuild assets with `npm run build`.

## Management, ledgers and reports

**Settings → Overview** includes a dedicated **Cheque settings & bank accounts** card within the settings grid. It is separate from Payment methods and remains fully visible. The settings and their staff guides follow `CHEQUE` and the existing permissions.

The dashboard **Cheques Due Soon** panel appears only when dashboard alerts are enabled, the user has the dashboard permission, and at least one visible pending cheque is overdue or due within the configured alert period. It is hidden when no matching cheque is available.

The Cheques menu provides All, Received, Issued, Third-Party, Due Soon and Returned views, plus Cheque Report. Search number, amount, bank, customer, supplier, reference, invoice or purchase. Filter type, status, bank, parties, cheque date range and recorded date range. Available Customer Cheques opens the transferable pool.

Details show document links and a user/date/reason timeline. Pending instruments can have a private scanned image/PDF attached (5 MB maximum). Downloads require authorized cheque access and the enabled feature. Passed attachments cannot be replaced through the normal form.

Customer/supplier directories show Due, Hold and total receivable/payable exposure separately for authorized roles. Profiles show Hold and cheque history. Ledgers keep receipt/issuance, Hold information, clearance and return/cancellation reversals; the running ledger balance represents normal Due, with Hold reported separately. A transfer reversal preserves the original supplier payment history.

Cheque Report offers all matching rows in PDF and CSV, without limiting exports to the screen page. Pending received, pending issued, third-party pending, passed and returned amounts are distinct. Filter passed records by type for cleared customer cheques, cleared own cheques or non-cash third-party settlements. General sales/purchase reports retain the original transaction value and add cheque Hold/settlement information. Payment method and cashflow reports exclude pending allocations; third-party passes are excluded from business cashflow. Register summaries show pending cheques as information alongside unchanged drawer cash.

## Returns and invoice corrections

Resolve pending cheques before returning, refunding or voiding their linked documents. The backend blocks unsafe settlement, including no-receipt returns linked to a cheque-backed original sale. This prevents a cash refund for money that has not cleared and duplicate credits. A cheque-backed invoice cannot be edited by replacing/deleting its existing payment snapshots. Use the audited settlement and return workflows.

After a cheque is returned/cancelled, its Due is restored and the normal Due-first return workflow can apply. Passed payments use the normal cleared return/refund workflow. These checks remain effective when the cheque UI is disabled, preserving existing accounting.

## Roles & permissions

The role editor includes independent cheque permissions:

- `cheques.view`: management lists, details and authorized attachments.
- `cheques.create`: standalone record form and pending attachments.
- `cheques.edit`: correct pending/transferred cheque metadata.
- `cheques.resolve_return`: record replacement instruments or payments for a returned cheque; the existing source and customer/supplier payment permissions also apply.
- `cheques.receive`, `cheques.issue`, `cheques.transfer`: permitted cheque sources.
- `cheques.reverse_transfer`: retrieve/reverse a pending supplier endorsement.
- `cheques.pass`, `cheques.return`, `cheques.cancel`: lifecycle actions.
- `cheques.view_customer`, `cheques.view_supplier`: account Hold/history/exposure.
- `cheques.manage_settings`: cheque settings and bank account references.
- `cheques.view_reports`, `cheques.export`: report access and PDF/CSV downloads.
- `cheques.dashboard`: dashboard alerts.
- `cheques.override_action_date`: explicit early clearance/return override.

Administrator has all permissions. When the new edit and resolve permissions are first installed, roles already holding `cheques.create` inherit metadata editing, and roles holding `cheques.return` inherit resolution access; existing payment and cheque-source permissions still apply. Assign or remove these independently in the role editor. Customer-origin cheque records follow existing sales visibility scope. Hiding a button is complemented by backend authorization.

## Troubleshooting

| Issue | Action |
| --- | --- |
| Cheque controls missing | Check CHEQUE, clear configuration/cache and check role/source settings. |
| Can't pass/return yet | Check the cheque date, configured action window and override permission. |
| Cheque unavailable for transfer | Check its status, previous transfer and sales visibility. |
| Cheque total rejected | Match the payment share exactly; do not exceed the outstanding payable. |
| Duplicate number rejected | Check the original physical instrument. Bank + account + number identify it; preserve leading zeros. |
| Return/refund blocked | Resolve pending instruments first, then follow the normal return workflow. |
| Cash drawer unchanged | Expected: cheque receipt/issuance/clearance is non-cash. |
| Hidden after disabling | Re-enable CHEQUE and clear cached configuration; records remain stored. |

## Verification

Verified on 14 October 2026:

- 24 cheque feature tests passed (251 assertions), covering lifecycle accounting, transfers and reversals, source restrictions, immutable payment history, permissions and disabled-feature behavior.
- Cheque editing/return recovery adds 11 feature tests covering optional reasons, split/partial replacements, cash/bank recovery, allocation limits, atomic rollback, duplicate submissions, stale edits, source permissions and third-party accounts. The focused cheque/account/purchase/demo/unit regression run passes 84 tests (1,263 assertions).
- Cheque, barcode and HR compatibility suites passed together: 67 tests, 699 assertions.
- 26 JavaScript tests passed; production assets built successfully, Blade views compiled and changed PHP files passed formatting checks.
- Disposable-database browser checks passed for multiple customer/own cheques, POS checkout, pass/return, linked supplier transfers, settings, reports, responsive layouts and `CHEQUE=false` hiding. POS checkout also rejects incomplete cheque details and mismatched totals before submission.
- The full suite finished with 426 passing tests and 46 failures. The unchanged baseline had 402 passing tests and the same 46 failures; no additional failure was introduced.

The local installation has the migration applied and `CHEQUE=true`. Workflow/browser fixtures were stored in a separate temporary SQLite database, not the operational store database.
