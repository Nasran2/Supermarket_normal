<?php

// Real application screens recorded in an isolated store with fictional data.
// Each frame references a measured control in documentation-screens.json.
$frame = fn (string $screen, ?string $target, string $caption) => compact('screen', 'target', 'caption');

return [
    'screens' => json_decode(file_get_contents(__DIR__.'/documentation-screens.json'), true, 512, JSON_THROW_ON_ERROR),
    'guides' => [
        'start-your-shift' => [
            [$frame('login', 'username', 'Enter your own username and password.'), $frame('login', 'signin', 'Click Sign in.')],
            [$frame('register-open', 'amount', 'Count the notes and coins, then enter the opening cash.')],
            [$frame('register-open', 'open', 'Click Open register & start selling.')],
            [$frame('pos-catalog', 'search', 'Your register is open. Start with the product search.')],
        ],
        'make-a-sale' => [
            [$frame('pos-catalog', 'search', 'Type the product name or scan its barcode.'), $frame('pos-search', 'product', 'Click the Bath Soap product card.')],
            [$frame('pos-price', 'price', 'Choose Rs. 130.00 from the real price options.'), $frame('pos-cart-one', 'quantity', 'Bath Soap is now in Your cart.')],
            [$frame('pos-cart-one', 'plus', 'Click + to add one more piece.'), $frame('pos-cart-two', 'quantity', 'Check: 2 pieces × Rs. 130 = Rs. 260.')],
            [$frame('pos-cart-two', 'customer', 'Leave Walk-in customer for an ordinary sale. Choose a name when needed.')],
            [$frame('pos-cart-two', 'payment', 'Check Rs. 260.00 and click Take payment.'), $frame('pos-payment', 'cash', 'Choose Cash in the payment window.'), $frame('pos-payment-paid', 'amount', 'Enter Rs. 300 received. Cash change is Rs. 40.')],
            [$frame('pos-payment-paid', 'complete', 'Check the figures and click Complete sale once.'), $frame('pos-receipt', 'print', 'The receipt opens. Print it and give Rs. 40 change.'), $frame('pos-receipt', 'next', 'Click Next sale for the next customer.')],
        ],
        'split-a-payment' => [
            [$frame('pos-credit-cart', 'payment', 'Check the Rs. 1,000 bill and open Take payment.')],
            [$frame('pos-split-cash', 'split', 'Choose Split payment.'), $frame('pos-split-cash', 'amount', 'Enter Rs. 400 as the cash share and cash received.')],
            [$frame('pos-split-cash', 'card', 'Click Card to add the second payment.'), $frame('pos-split-complete', 'amount', 'The remaining Rs. 600 goes on Card.')],
            [$frame('pos-split-complete', 'summary', 'Check Received Rs. 1,000 and Balance due Rs. 0.'), $frame('pos-split-complete', 'complete', 'Click Complete sale when everything is correct.')],
        ],
        'sell-on-credit' => [
            [$frame('pos-credit-cart', 'customer', 'Select the named customer before payment.')],
            [$frame('pos-credit-payment', 'amount', 'Enter Rs. 400 received.'), $frame('pos-credit-payment', 'summary', 'Check the Rs. 600 balance due.')],
            [$frame('pos-credit-payment', 'due', 'If nothing is paid, choose No payment · leave bill due.'), $frame('pos-unpaid', 'summary', 'The whole Rs. 1,000 bill is unpaid.')],
            [$frame('pos-credit-payment', 'complete', 'For a partly paid bill, check Rs. 600 and click Complete with due.')],
        ],
        'collect-customer-payment' => [
            [$frame('customers-list', 'search', 'Search for the customer.'), $frame('customers-list', 'customer', 'Open the correct customer account.')],
            [$frame('customer-account', 'bill', 'Open the unpaid invoice.'), $frame('sale-details', 'pay', 'Choose Pay due on that invoice.')],
            [$frame('sale-collect', 'amount', 'Enter the Rs. 100 actually received.'), $frame('sale-collect', 'save', 'Click Receive payment.')],
            [$frame('sale-collected', 'balance', 'The invoice now shows Rs. 120 remaining due.')],
        ],
        'return-with-bill' => [
            [$frame('return-search', 'search', 'Search the original invoice or customer.'), $frame('return-search', 'select', 'Select the matching bill.')],
            [$frame('return-items', 'quantity', 'Enter only the quantity brought back.')],
            [$frame('return-items', 'action', 'Choose Restock, Write off or Return to supplier.')],
            [$frame('return-resolution', 'money', 'Choose the right resolution: product exchange or money back.')],
            [$frame('return-settlement', 'summary', 'Review the return value and any original due reduction.'), $frame('return-settlement', 'method', 'Choose the permitted refund method.')],
            [$frame('return-settlement', 'complete', 'Complete the return once.'), $frame('return-completed', 'receipt', 'Open the 80mm credit note.')],
        ],
        'return-without-bill' => [
            [$frame('return-search', 'without', 'Choose Return Without Bill.')],
            [$frame('no-bill-search', 'customer', 'Choose a customer if known.'), $frame('no-bill-search', 'search', 'Search or scan the returned product.')],
            [$frame('no-bill-history', 'matches', 'Review Possible Original Purchases. Link only the correct sale, or continue without a match when unsure.')],
            [$frame('no-bill-details', 'price', 'Confirm the return credit price.'), $frame('no-bill-details', 'stock', 'Choose the correct stock action.')],
            [$frame('no-bill-resolution', 'other', 'Choose an exchange or Money / Account Credit.')],
            [$frame('no-bill-settlement', 'summary', 'Check return credit, replacements and customer due.'), $frame('no-bill-settlement', 'method', 'Use an enabled refund method. Cash depends on store policy.')],
            [$frame('no-bill-settlement', 'complete', 'Click Complete Return once and give the resulting credit note.')],
        ],
        'receive-a-purchase' => [
            [$frame('purchase-details', 'supplier', 'Choose the supplier and check the delivery date.')],
            [$frame('purchase-details', 'search', 'Search for each delivered product.'), $frame('purchase-items', 'items', 'Enter the received quantity.')],
            [$frame('purchase-items', 'items', 'Check Unit cost and Selling price for this delivery.')],
            [$frame('purchase-payment', 'charge', 'Use Shipping & other charges for delivery or handling costs.')],
            [$frame('purchase-payment', 'method', 'Choose how you paid the supplier.'), $frame('purchase-payment', 'amount', 'Enter only what you paid now.')],
            [$frame('purchase-payment', 'save', 'Review the purchase summary, then Receive stock & save.')],
        ],
        'pay-a-supplier' => [
            [$frame('purchase-account', 'items', 'Check the supplier, reference and delivered products.')],
            [$frame('purchase-account', 'pay', 'Click Pay due.')],
            [$frame('supplier-payment', 'amount', 'Enter Rs. 400 given to the supplier.'), $frame('supplier-payment', 'save', 'Choose the method and Record payment.')],
            [$frame('supplier-paid', 'pay', 'Rs. 400 remains due. Check the recorded payment history.')],
        ],
        'purchase-return' => [
            [$frame('purchase-return-search', 'search', 'Search by reference, supplier or product.'), $frame('purchase-return-search', 'select', 'Select the original purchase.')],
            [$frame('purchase-return-items', 'quantity', 'Enter the returned quantity and reason.')],
            [$frame('purchase-return-resolution', 'money', 'Choose the supplier’s resolution.')],
            [$frame('purchase-return-settlement', 'summary', 'Check credit and reduction of the original purchase due.')],
            [$frame('purchase-return-settlement', 'complete', 'Complete the return once and keep its record.')],
        ],
        'add-a-product' => [
            [$frame('product-details', 'name', 'Enter the product name and barcode / SKU.')],
            [$frame('product-details', 'category', 'Choose the product category.'), $frame('product-details', 'supplier', 'Choose the supplier who provides this product.')],
            [$frame('product-stock', 'unit', 'Choose the primary stock unit: pieces, weight or volume.')],
            [$frame('product-stock', 'price', 'Check opening quantities, cost and selling price.')],
            [$frame('product-stock', 'save', 'Check the product is active, then Create product.')],
        ],
        'adjust-stock' => [
            [$frame('adjustment-form', 'quantity', 'Count the physical products before entering a correction.')],
            [$frame('adjustment-form', 'reason', 'Explain the correction.'), $frame('adjustment-form', 'search', 'Search and add each product.')],
            [$frame('adjustment-form', 'mode', 'Choose Add stock, Remove stock or Set counted stock.')],
            [$frame('adjustment-form', 'quantity', 'Check Quantity and New stock.'), $frame('adjustment-form', 'save', 'Click Apply adjustment only when the figures are correct.')],
        ],
        'units-and-categories' => [
            [$frame('category-form', 'name', 'Name a category, such as Groceries or Personal Care.')],
            [$frame('unit-form', 'name', 'Name the unit and its short name.'), $frame('unit-form', 'decimal', 'Allow decimals for weight or volume when needed.')],
            [$frame('product-stock', 'conversions', 'Additional units & conversions defines how a pack relates to the primary unit.')],
            [$frame('pos-cart-two', 'quantity', 'Check the selling unit and quantity in the cart.')],
        ],
        'customers-and-suppliers' => [
            [$frame('customer-form', 'name', 'Use Add customer for someone buying from you.'), $frame('supplier-form', 'name', 'Use Add supplier for a business supplying your stock.')],
            [$frame('customer-form', 'name', 'Enter a recognizable name.'), $frame('customer-form', 'phone', 'Add contact details when available.')],
            [$frame('customer-form', 'due', 'Enter an old balance only if the customer already owes you money.')],
            [$frame('customer-form', 'save', 'Create the customer after checking the details.')],
        ],
        'record-an-expense' => [
            [$frame('expense-form', 'category', 'Choose a useful category, such as Utility Bills.')],
            [$frame('expense-form', 'amount', 'Enter the actual amount.'), $frame('expense-form', 'description', 'Explain what the spending was for.')],
            [$frame('expense-form', 'method', 'Check how you paid.'), $frame('expense-form', 'save', 'Click Create expense after reviewing it.')],
            [$frame('expense-history', 'table', 'Find the saved expense in history.')],
        ],
        'read-reports' => [
            [$frame('reports-library', 'sales', 'Choose the report you need.')],
            [$frame('report-sales', 'from', 'Set From and To dates.'), $frame('report-sales', 'filter', 'Choose other filters, then Apply filters.')],
            [$frame('report-sales', 'table', 'Read the column headings and totals carefully.')],
            [$frame('report-sales', 'table', 'Check returned amounts and balance due as well as the original sale.')],
            [$frame('report-sales', 'pdf', 'Use Download PDF, Print or Export CSV.')],
        ],
        'close-your-shift' => [
            [$frame('register-summary', 'close', 'Finish pending work, then Review & close register.')],
            [$frame('register-close', 'amount', 'Count the drawer and enter Actual cash counted.')],
            [$frame('register-close', 'amount', 'Compare Actual cash counted with expected cash and review the difference.')],
            [$frame('register-close', 'confirm', 'Add a closing note if needed, then Confirm & close register.')],
        ],
        'store-settings' => [
            [$frame('settings-overview', 'business', 'Choose the settings card for the change you need.')],
            [$frame('settings-business', 'name', 'Check the shop name, address and contact details.')],
            [$frame('settings-receipt', 'width', 'Match the thermal paper width to your printer.')],
            [$frame('settings-returns', 'rule', 'Review stock and return rules with your manager.')],
            [$frame('settings-receipt', 'save', 'Review the changes, then click Save changes.')],
        ],
        'users-and-access' => [
            [$frame('role-form', 'name', 'Review the role that matches the staff member’s job.')],
            [$frame('role-form', 'visibility', 'Check sales visibility.'), $frame('role-form', 'permissions', 'Review only the permissions that are needed.')],
            [$frame('user-form', 'username', 'Enter separate login details for each person.'), $frame('user-form', 'role', 'Choose the correct role before creating the user.')],
            [$frame('login', 'signin', 'Have the staff member sign in and check the available menus.')],
        ],
        'staff-and-payroll' => [
            [$frame('staff-form', 'name', 'Add staff details, employment information and salary rates.')],
            [$frame('attendance-form', 'date', 'Choose the working date.'), $frame('attendance-form', 'status', 'Record the attendance status and review leave.')],
            [$frame('payroll-form', 'month', 'Choose the staff member and salary period before calculating a draft.')],
            [$frame('staff-payments', 'staff', 'Choose the right staff member.'), $frame('staff-payments', 'amount', 'Record only the money actually paid or advanced.')],
        ],
        'common-questions' => [
            [$frame('pos-catalog', 'all', 'Clear the search and choose All products.')],
            [$frame('pos-price', 'price', 'Choose the price that matches the goods being sold.')],
            [$frame('role-form', 'permissions', 'Ask the manager to check your role, settings and register status.')],
            [$frame('pos-receipt', 'print', 'Open the saved receipt, then check the printer and paper.')],
            [$frame('sale-details', 'items', 'Check All sales and compare the saved invoice before repeating a sale.')],
            [$frame('pos-payment-paid', 'summary', 'Check quantity, prices, payments, charges and return adjustments.')],
        ],
    ],
];
