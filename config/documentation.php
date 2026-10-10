<?php

return [
    'start-your-shift' => [
        'title' => 'Start your shift',
        'category' => 'Daily selling',
        'description' => 'Count the money in the drawer and get ready to serve customers.',
        'path' => 'Daily register → Register & history',
        'icon' => 'wallet',
        'minutes' => 2,
        'before' => '',
        'tip' => 'Opening cash is the money already in the drawer. It is not a sale.',
        'steps' => [
            [
                'title' => 'Sign in',
                'text' => 'Enter the username and password your manager gave you. Click Sign in.',
                'action' => 'Sign in',
                'fields' => [
                    'Username' => 'Your own username',
                    'Password' => '••••••••',
                ],
                'result' => 'Your store workspace opens.',
            ],
            [
                'title' => 'Count the cash already in the drawer',
                'text' => 'Count the notes and coins before taking any customer payments. This is your opening cash. Example: if the drawer contains Rs. 5,000, enter 5,000.',
                'action' => 'Count opening cash',
                'fields' => [
                    'Notes and coins' => 'Rs. 5,000',
                    'Opening cash' => '5,000',
                ],
                'result' => 'You know how much money you started with.',
            ],
            [
                'title' => 'Open the register',
                'text' => 'Open Daily register → Register & history, then choose Open register. You can also open it from the Point of sale screen. Check the opening amount before you continue.',
                'action' => 'Open register & start selling',
                'fields' => [
                    'Opening cash' => '5,000',
                ],
                'result' => 'Register open appears at the top.',
            ],
            [
                'title' => 'Go to Point of sale',
                'text' => 'Click Point of sale in the menu. You are ready to scan or choose products. Use your own account so your work is recorded under your name.',
                'action' => 'Point of sale',
                'fields' => [
                    'Register' => 'Open',
                    'Cart' => 'Empty',
                ],
                'result' => 'You can start the first sale.',
            ],
        ],
        'link' => [
            'route' => 'register.index',
            'permission' => 'register.view',
            'parameters' => [],
        ],
    ],
    'make-a-sale' => [
        'title' => 'Make a sale',
        'category' => 'Daily selling',
        'description' => 'Choose products, take the payment and give the customer a receipt.',
        'path' => 'Point of sale',
        'icon' => 'scan-line',
        'minutes' => 4,
        'before' => 'Your register must be open. If a button is unavailable, ask your manager to check your access.',
        'tip' => 'Do not complete a sale until you have checked the payment. F1 goes to search; F4 opens payment.',
        'steps' => [
            [
                'title' => 'Find the product',
                'text' => 'Click the search box and scan the barcode, or type the product name. You can also choose a category and scroll down the product list.',
                'action' => 'Choose Bath Soap',
                'fields' => [
                    'Search' => 'Bath Soap',
                    'Selling price' => 'Rs. 130',
                ],
                'result' => 'Bath Soap is added to the cart.',
            ],
            [
                'title' => 'Choose a price when asked',
                'text' => 'Some products have stock at different selling prices. If a price box opens, choose the correct price for the product the customer is buying.',
                'action' => 'Choose Rs. 130',
                'fields' => [
                    'Available price 1' => 'Rs. 130',
                    'Available price 2' => 'Rs. 140',
                ],
                'result' => 'The selected price appears in the cart.',
            ],
            [
                'title' => 'Check the quantity',
                'text' => 'Use + or − beside the product, or enter the quantity. Scan again to add another of the same item. Review the unit too: pieces, kilograms or another unit.',
                'action' => 'Set quantity',
                'fields' => [
                    'Bath Soap' => '2 pcs',
                    'Each' => 'Rs. 130',
                    'Amount' => 'Rs. 260',
                ],
                'result' => 'The cart shows the right quantity and amount.',
            ],
            [
                'title' => 'Choose the customer if needed',
                'text' => 'Leave Walk-in customer for an ordinary sale. Choose a named customer if you need their purchase history or want to leave an unpaid amount.',
                'action' => 'Choose customer',
                'fields' => [
                    'Customer' => 'Walk-in customer',
                ],
                'result' => 'The sale uses the customer you selected.',
            ],
            [
                'title' => 'Take payment',
                'text' => 'Check the Order total, then click Take payment. Choose Cash, Card, QR or another enabled method. Enter what the customer actually gives you.',
                'action' => 'Take payment',
                'fields' => [
                    'Order total' => 'Rs. 260',
                    'Cash received' => 'Rs. 300',
                ],
                'result' => 'Cash change is Rs. 40.',
            ],
            [
                'title' => 'Complete and give the receipt',
                'text' => 'Check the amount, method and change. Click Complete sale once. When the receipt appears, print it if needed and give the correct change. Choose Next sale for the next customer.',
                'action' => 'Complete sale',
                'fields' => [
                    'Bill total' => 'Rs. 260',
                    'Received' => 'Rs. 300',
                    'Change' => 'Rs. 40',
                ],
                'result' => 'The sale is saved and stock is reduced.',
            ],
        ],
        'link' => [
            'route' => 'pos.index',
            'permission' => 'pos.access',
            'parameters' => [],
        ],
    ],
    'split-a-payment' => [
        'title' => 'Take a split payment',
        'category' => 'Daily selling',
        'description' => 'Let a customer pay part in cash and the rest another way.',
        'path' => 'Point of sale → Take payment',
        'icon' => 'credit-card',
        'minutes' => 2,
        'before' => '',
        'tip' => 'A payment charge is an extra fee shown by the system. Check whether the customer or store pays it.',
        'steps' => [
            [
                'title' => 'Prepare the cart',
                'text' => 'Add all products and check the total before opening payment.',
                'action' => 'Take payment',
                'fields' => [
                    'Order total' => 'Rs. 1,000',
                ],
                'result' => 'The payment window opens.',
            ],
            [
                'title' => 'Choose Split payment',
                'text' => 'Click Split payment. Choose the first payment method and enter the part paid with that method.',
                'action' => 'Split payment',
                'fields' => [
                    'Cash' => 'Rs. 400',
                ],
                'result' => 'Rs. 600 remains to allocate.',
            ],
            [
                'title' => 'Enter the second part',
                'text' => 'Add another enabled payment method, such as Card, and enter the remaining amount. Check any displayed processing charge.',
                'action' => 'Choose Card',
                'fields' => [
                    'Cash' => 'Rs. 400',
                    'Card' => 'Rs. 600',
                ],
                'result' => 'The payment parts cover the bill.',
            ],
            [
                'title' => 'Review and finish',
                'text' => 'Check every payment line, Received, Balance due and Cash change. Only cash can be given back as cash change. Click Complete sale when the figures are correct.',
                'action' => 'Complete sale',
                'fields' => [
                    'Total parts' => 'Rs. 1,000',
                    'Balance due' => 'Rs. 0',
                ],
                'result' => 'Both payment methods are recorded on one sale.',
            ],
        ],
        'link' => [
            'route' => 'pos.index',
            'permission' => 'pos.access',
            'parameters' => [],
        ],
    ],
    'sell-on-credit' => [
        'title' => 'Leave an amount for the customer to pay later',
        'category' => 'Daily selling',
        'description' => 'Record a partly paid or unpaid sale against a named customer.',
        'path' => 'Point of sale → Take payment',
        'icon' => 'contact',
        'minutes' => 2,
        'before' => '',
        'tip' => 'Due means money that has not been paid yet. Ask your manager if your account cannot make sales with due.',
        'steps' => [
            [
                'title' => 'Select the right customer',
                'text' => 'Choose the customer in the cart before payment. Do not leave Walk-in customer when recording a debt. Check the name carefully.',
                'action' => 'Choose customer',
                'fields' => [
                    'Customer' => 'Example Customer',
                    'Bill total' => 'Rs. 1,000',
                ],
                'result' => 'This customer will be responsible for the unpaid amount.',
            ],
            [
                'title' => 'Enter any payment received',
                'text' => 'If the customer pays Rs. 400 now, enter Rs. 400 under the correct payment method. The balance should be Rs. 600.',
                'action' => 'Enter received amount',
                'fields' => [
                    'Bill total' => 'Rs. 1,000',
                    'Paid now' => 'Rs. 400',
                    'Balance due' => 'Rs. 600',
                ],
                'result' => 'The unpaid part is shown clearly.',
            ],
            [
                'title' => 'For a fully unpaid bill, choose no payment',
                'text' => 'If the customer pays nothing now, choose No payment · leave bill due. This is available only for authorized staff.',
                'action' => 'No payment · leave bill due',
                'fields' => [
                    'Paid now' => 'Rs. 0',
                    'Balance due' => 'Rs. 1,000',
                ],
                'result' => 'The whole bill is left unpaid.',
            ],
            [
                'title' => 'Check and complete with due',
                'text' => 'Review the named customer and the unpaid amount, then use Complete with due. Give the receipt to the customer.',
                'action' => 'Complete with due',
                'fields' => [
                    'Customer' => 'Example Customer',
                    'Balance due' => 'Rs. 600',
                ],
                'result' => 'The customer balance increases by the unpaid amount.',
            ],
        ],
        'link' => [
            'route' => 'pos.index',
            'permission' => 'pos.access',
            'parameters' => [],
        ],
    ],
    'collect-customer-payment' => [
        'title' => 'Collect a customer’s unpaid balance',
        'category' => 'People & payments',
        'description' => 'Record money received for an earlier bill without making a new sale.',
        'path' => 'Customers → All customers',
        'icon' => 'circle-dollar-sign',
        'minutes' => 2,
        'before' => '',
        'tip' => 'Do not make another product sale just to collect money for an old bill.',
        'steps' => [
            [
                'title' => 'Find the customer',
                'text' => 'Open Customers → All customers. Search by name or phone and open the correct customer.',
                'action' => 'Open customer',
                'fields' => [
                    'Search' => 'Amal Perera',
                ],
                'result' => 'The customer balance and bills are shown.',
            ],
            [
                'title' => 'Find the unpaid bill',
                'text' => 'Open the bill you are collecting payment for. Compare the bill number and amount with the customer.',
                'action' => 'Open bill',
                'fields' => [
                    'Bill' => 'INV-20261010-00001',
                    'Unpaid amount' => 'Rs. 220',
                ],
                'result' => 'You have selected the correct bill.',
            ],
            [
                'title' => 'Record the payment',
                'text' => 'Use the payment collection option on the bill. Enter the amount actually received, the payment method and a reference if needed.',
                'action' => 'Receive payment',
                'fields' => [
                    'Amount received' => 'Rs. 100',
                    'Method' => 'Cash',
                ],
                'result' => 'The payment is added to the bill.',
            ],
            [
                'title' => 'Check the remaining amount',
                'text' => 'Confirm the new bill due and customer balance. In this example Rs. 120 remains on that bill. Give the payment receipt if available.',
                'action' => 'Check balance',
                'fields' => [
                    'Previous due' => 'Rs. 220',
                    'Received' => 'Rs. 100',
                    'Remaining due' => 'Rs. 120',
                ],
                'result' => 'The customer history shows the collection.',
            ],
        ],
        'link' => [
            'route' => 'manage.index',
            'permission' => 'customers.view',
            'parameters' => [
                'resource' => 'customers',
            ],
        ],
    ],
    'return-with-bill' => [
        'title' => 'Return a product with the bill',
        'category' => 'Returns & exchanges',
        'description' => 'Find the original bill and return only the items the customer brings back.',
        'path' => 'Sales → Sales Return → Return With Bill',
        'icon' => 'receipt-text',
        'minutes' => 4,
        'before' => '',
        'tip' => 'Use Return With Bill whenever you can find the original sale. A credit note is the receipt for the return.',
        'steps' => [
            [
                'title' => 'Find the original bill',
                'text' => 'Choose Return With Bill. Search for the invoice number or customer and open the right bill.',
                'action' => 'Search',
                'fields' => [
                    'Bill number' => 'INV-000125',
                ],
                'result' => 'The original items and returnable quantities appear.',
            ],
            [
                'title' => 'Enter the returned quantities',
                'text' => 'Enter only the quantities being returned. Check the Remaining quantity. Choose a main reason for the return.',
                'action' => 'Choose returned items',
                'fields' => [
                    'Bath Soap returned' => '1 pcs',
                    'Reason' => 'Customer changed mind',
                ],
                'result' => 'The return uses the original bill value.',
            ],
            [
                'title' => 'Choose what happens to each product',
                'text' => 'Restock puts a sellable item back on the shelf. Write off is for an item that cannot be sold. Return to supplier sends it to the supplier. Choose separately for each product.',
                'action' => 'Choose stock action',
                'fields' => [
                    'Bath Soap' => 'Restock',
                ],
                'result' => 'The product has the correct stock action.',
            ],
            [
                'title' => 'Choose what the customer receives',
                'text' => 'Choose Same product, Another product or Money back. For an exchange, check the replacement products, quantities and price.',
                'action' => 'Choose resolution',
                'fields' => [
                    'Return credit' => 'Rs. 130',
                    'Resolution' => 'Money back',
                ],
                'result' => 'The system calculates the difference.',
            ],
            [
                'title' => 'Check the settlement',
                'text' => 'If the original bill is still unpaid, the return reduces that bill’s due first. Check the refund, replacement value and any other customer due credit before finishing.',
                'action' => 'Review & settle',
                'fields' => [
                    'Return credit' => 'Rs. 130',
                    'Original bill due reduced' => 'Rs. 130',
                    'Cash refund' => 'Rs. 0',
                ],
                'result' => 'You can see where every part of the credit goes.',
            ],
            [
                'title' => 'Complete and print',
                'text' => 'Check the customer, items, reason and settlement. Click Complete return once, then open the 80mm credit note if a printed copy is needed.',
                'action' => 'Complete return',
                'fields' => [
                    'Status' => 'Completed',
                ],
                'result' => 'Stock, payments and customer balance are updated.',
            ],
        ],
        'link' => [
            'route' => 'returns.create',
            'permission' => 'sales_returns.create',
            'parameters' => [
                'kind' => 'sales',
            ],
        ],
    ],
    'return-without-bill' => [
        'title' => 'Return a product without the bill',
        'category' => 'Returns & exchanges',
        'description' => 'Use a careful return process when the customer has lost their receipt.',
        'path' => 'Sales → Sales Return → Return Without Bill',
        'icon' => 'package-open',
        'minutes' => 4,
        'before' => '',
        'tip' => 'Verified means linked to a confirmed old sale. No Receipt means the original sale is unknown. Estimated cost is a suggested stock cost, not proof of what the store originally paid.',
        'steps' => [
            [
                'title' => 'Choose Return Without Bill',
                'text' => 'Open Sales Return and choose Return Without Bill. Select the customer if known, or leave Walk-in Customer. A named customer helps the system find an earlier purchase.',
                'action' => 'Return Without Bill',
                'fields' => [
                    'Customer' => 'Example Customer',
                ],
                'result' => 'You can search products without choosing a whole bill.',
            ],
            [
                'title' => 'Scan or search the returned products',
                'text' => 'Scan the barcode or search by product name. Add every returned product. Scanning the same product again increases its quantity where appropriate.',
                'action' => 'Add Bath Soap',
                'fields' => [
                    'Returned product' => 'Bath Soap',
                    'Quantity' => '2 pcs',
                ],
                'result' => 'The returned products are listed.',
            ],
            [
                'title' => 'Check possible old purchases',
                'text' => 'If a matching purchase appears, check the date, quantity and price with the customer. Click Link This Sale only when it is the correct purchase. Otherwise choose Continue Without Original Sale.',
                'action' => 'Link This Sale',
                'fields' => [
                    'Possible bill' => 'INV-000125',
                    'Bought' => '2 pcs at Rs. 130',
                ],
                'result' => 'A confirmed match uses the original bill information.',
            ],
            [
                'title' => 'Confirm the credit and stock action',
                'text' => 'For an unmatched item, the suggested price is only a suggestion. Confirm Return Credit Price. Choose Restock, Write off or Return to supplier for each item. Supplier returns need a confirmed supplier. Price changes may need manager access and a reason.',
                'action' => 'Confirm return details',
                'fields' => [
                    'Quantity' => '2 pcs',
                    'Credit each' => 'Rs. 130',
                    'Stock action' => 'Restock',
                ],
                'result' => 'The approved return credit is Rs. 260.',
            ],
            [
                'title' => 'Choose an exchange or money back',
                'text' => 'Choose Same Product, Another Product or Money / Account Credit. Replacement products use available shop stock. If the replacement costs more, the customer pays the difference.',
                'action' => 'Choose Another Product',
                'fields' => [
                    'Return credit' => 'Rs. 260',
                    'Replacement' => 'Rs. 300',
                ],
                'result' => 'The customer pays Rs. 40.',
            ],
            [
                'title' => 'Review money and customer balance',
                'text' => 'Read Return Credit, Due Before, Applied to Due and Due After. You must deliberately choose any customer due credit. Walk-in returns cannot reduce a customer balance. Cash refunds may be disabled by your manager.',
                'action' => 'Review settlement',
                'fields' => [
                    'Return credit' => 'Rs. 260',
                    'Customer due credit' => 'Rs. 0',
                    'Customer pays' => 'Rs. 40',
                ],
                'result' => 'The settlement is clear before saving.',
            ],
            [
                'title' => 'Complete once and give the credit note',
                'text' => 'Check all figures and click Complete return. A manager may need to complete it under their account. The receipt says Without Bill and records any confirmed bill links.',
                'action' => 'Complete return',
                'fields' => [
                    'Return type' => 'Without Bill',
                    'Status' => 'Completed',
                ],
                'result' => 'The return, stock and money movements are saved.',
            ],
        ],
        'link' => [
            'route' => 'returns.create',
            'permission' => ['sales_returns.create', 'sales_returns.no_receipt'],
            'setting' => 'no_receipt_enabled',
            'parameters' => [
                'kind' => 'sales',
                'return_type' => 'NO_RECEIPT',
            ],
        ],
    ],
    'receive-a-purchase' => [
        'title' => 'Receive stock from a supplier',
        'category' => 'Products & stock',
        'description' => 'Record a delivery, its prices and what you paid the supplier.',
        'path' => 'Purchases → Add purchase',
        'icon' => 'truck',
        'minutes' => 4,
        'before' => '',
        'tip' => 'A purchase means goods your store receives from a supplier. A sale means goods your store sells to a customer.',
        'steps' => [
            [
                'title' => 'Choose the supplier and delivery date',
                'text' => 'Open Add purchase. Select the supplier who delivered the goods and enter the delivery date. The store gives this purchase its own reference number.',
                'action' => 'Choose supplier',
                'fields' => [
                    'Supplier' => 'Example Supplier',
                    'Delivery date' => 'Today',
                ],
                'result' => 'The delivery is linked to the right supplier.',
            ],
            [
                'title' => 'Add the delivered products',
                'text' => 'Search or scan each product. Enter the actual quantity received and the correct unit. Add a new product first if it is missing from the product list.',
                'action' => 'Add product',
                'fields' => [
                    'Product' => 'Bath Soap',
                    'Quantity' => '20 pcs',
                ],
                'result' => 'The delivery items are listed.',
            ],
            [
                'title' => 'Enter cost and selling price',
                'text' => 'Cost / unit is what the store pays. Sell / unit is what the customer pays. Check both before continuing. New delivery prices do not rewrite old stock prices.',
                'action' => 'Check prices',
                'fields' => [
                    'Cost / unit' => 'Rs. 100',
                    'Sell / unit' => 'Rs. 130',
                    '20 items cost' => 'Rs. 2,000',
                ],
                'result' => 'The goods have the correct cost and selling price.',
            ],
            [
                'title' => 'Add delivery charges if needed',
                'text' => 'Enter shipping or other charges only when they apply. Check the purchase total and how the charges are handled on screen.',
                'action' => 'Review charges',
                'fields' => [
                    'Goods' => 'Rs. 2,000',
                    'Delivery charges' => 'Rs. 0',
                ],
                'result' => 'The total matches the supplier delivery.',
            ],
            [
                'title' => 'Enter the supplier payment',
                'text' => 'Enter Amount paid now and choose the method. Use 0 if you pay nothing now. If paying cash, check whether Take from register matches where you took the money from.',
                'action' => 'Enter Amount paid now',
                'fields' => [
                    'Purchase total' => 'Rs. 2,000',
                    'Paid now' => 'Rs. 1,500',
                    'Still owed' => 'Rs. 500',
                ],
                'result' => 'The remaining supplier balance is shown.',
            ],
            [
                'title' => 'Save and check',
                'text' => 'Review the supplier, quantities, prices, charges and payment. Save the purchase using the button at the bottom. Check the saved delivery and stock quantities.',
                'action' => 'Receive stock & save',
                'fields' => [
                    'Goods received' => '20 pcs',
                    'Supplier due' => 'Rs. 500',
                ],
                'result' => 'Stock increases and the supplier payment is recorded.',
            ],
        ],
        'link' => [
            'route' => 'purchases.create',
            'permission' => 'purchases.create',
            'parameters' => [],
        ],
    ],
    'pay-a-supplier' => [
        'title' => 'Pay an outstanding supplier bill',
        'category' => 'People & payments',
        'description' => 'Record a later supplier payment and check what is still owed.',
        'path' => 'Purchases → All purchases',
        'icon' => 'wallet',
        'minutes' => 2,
        'before' => '',
        'tip' => '',
        'steps' => [
            [
                'title' => 'Open the correct purchase',
                'text' => 'Find the supplier’s purchase and open it. Compare its reference and unpaid amount with the supplier’s bill.',
                'action' => 'Open purchase',
                'fields' => [
                    'Supplier' => 'Example Supplier',
                    'Unpaid amount' => 'Rs. 500',
                ],
                'result' => 'The purchase payment history is visible.',
            ],
            [
                'title' => 'Open the payment option',
                'text' => 'Use the purchase payment controls to add a payment. Do not enter the same payment again if it is already in the history.',
                'action' => 'Add payment',
                'fields' => [
                    'Existing payments' => 'Rs. 1,500',
                ],
                'result' => 'You are recording a new payment only.',
            ],
            [
                'title' => 'Enter what you pay now',
                'text' => 'Choose the payment method, amount and reference. For cash, check the option about taking money from the register.',
                'action' => 'Receive payment',
                'fields' => [
                    'Paid now' => 'Rs. 300',
                    'Method' => 'Bank Transfer',
                ],
                'result' => 'The payment is recorded against this purchase.',
            ],
            [
                'title' => 'Check the balance',
                'text' => 'Review the remaining due and supplier account history. Example: paying Rs. 300 against Rs. 500 due leaves Rs. 200.',
                'action' => 'Check supplier balance',
                'fields' => [
                    'Previous due' => 'Rs. 500',
                    'New payment' => 'Rs. 300',
                    'Remaining due' => 'Rs. 200',
                ],
                'result' => 'The supplier history agrees with the payment.',
            ],
        ],
        'link' => [
            'route' => 'purchases.index',
            'permission' => 'purchases.view',
            'parameters' => [],
        ],
    ],
    'purchase-return' => [
        'title' => 'Return delivered goods to a supplier',
        'category' => 'Returns & exchanges',
        'description' => 'Start with the original purchase and agree how the supplier settles it.',
        'path' => 'Purchases → Purchase Return',
        'icon' => 'truck',
        'minutes' => 3,
        'before' => '',
        'tip' => 'Goods marked Return to supplier in a customer return appear in Supplier Returns. A pending claim is not automatically a supplier payment.',
        'steps' => [
            [
                'title' => 'Find the purchase',
                'text' => 'Open Purchase Return and search the original purchase reference or supplier. Choose the delivery that contained these goods.',
                'action' => 'Find purchase',
                'fields' => [
                    'Purchase reference' => 'PUR-000025',
                ],
                'result' => 'The originally received products appear.',
            ],
            [
                'title' => 'Enter quantities and the reason',
                'text' => 'Enter only the goods being returned. Check the remaining quantity that can be returned and add the reason.',
                'action' => 'Select returned items',
                'fields' => [
                    'Product' => 'Bath Soap',
                    'Returned quantity' => '2 pcs',
                    'Reason' => 'Damaged delivery',
                ],
                'result' => 'The system calculates the original return value.',
            ],
            [
                'title' => 'Choose the supplier’s resolution',
                'text' => 'Choose Same product, Another product or Money / credit back. Check replacement quantities and prices if exchanging goods.',
                'action' => 'Money / credit back',
                'fields' => [
                    'Return value' => 'Rs. 200',
                ],
                'result' => 'The difference is calculated.',
            ],
            [
                'title' => 'Check supplier settlement',
                'text' => 'Read the original purchase due reduction first. If credit remains, choose an available refund or supplier-credit option. Do not assume the supplier has paid until it is actually received.',
                'action' => 'Review & settle',
                'fields' => [
                    'Original purchase due' => 'Rs. 500',
                    'Due reduced' => 'Rs. 200',
                    'Remaining due' => 'Rs. 300',
                ],
                'result' => 'The return and supplier balance agree.',
            ],
            [
                'title' => 'Complete and keep the note',
                'text' => 'Check the purchase, goods and settlement, then click Complete return once. Keep or print the return note.',
                'action' => 'Complete return',
                'fields' => [
                    'Status' => 'Completed',
                ],
                'result' => 'Returned quantities leave stock and the settlement is recorded.',
            ],
        ],
        'link' => [
            'route' => 'returns.create',
            'permission' => 'purchase_returns.create',
            'parameters' => [
                'kind' => 'purchase',
            ],
        ],
    ],
    'add-a-product' => [
        'title' => 'Add a new product',
        'category' => 'Products & stock',
        'description' => 'Give an item a name, unit, starting stock and selling price.',
        'path' => 'Products → Add product',
        'icon' => 'package',
        'minutes' => 3,
        'before' => '',
        'tip' => 'Use Add purchase for a new supplier delivery after the product exists. Do not add that same delivery again as opening stock.',
        'steps' => [
            [
                'title' => 'Enter the product details',
                'text' => 'Open Add product. Give it a name that staff will recognize. Scan or type Barcode / SKU if available. A SKU is simply a code used to identify an item.',
                'action' => 'Enter product information',
                'fields' => [
                    'Product name' => 'Bath Soap 100g',
                    'Barcode / SKU' => 'SOAP-100',
                ],
                'result' => 'The product is easy to find at checkout.',
            ],
            [
                'title' => 'Choose its category and supplier',
                'text' => 'Choose a useful category, such as Personal Care. Select the supplier if known. These choices help organize products.',
                'action' => 'Choose category',
                'fields' => [
                    'Category' => 'Personal Care',
                    'Supplier' => 'Example Supplier',
                ],
                'result' => 'Staff can find it under the correct category.',
            ],
            [
                'title' => 'Choose how you count it',
                'text' => 'Select Primary stock unit. Use pieces for individually counted goods or kilograms for goods sold by weight. Use a decimal-enabled unit for fractions such as 0.250 kg.',
                'action' => 'Choose primary stock unit',
                'fields' => [
                    'Primary stock unit' => 'Piece (pcs)',
                    'Low stock alert' => '5',
                ],
                'result' => 'The system knows how to count this product.',
            ],
            [
                'title' => 'Enter starting quantities and prices',
                'text' => 'Opening stock is the quantity already in your shop when you add this product. Enter quantity, cost and selling price. Add another price row if part of the starting stock has a different price.',
                'action' => 'Enter opening stock',
                'fields' => [
                    'Quantity' => '20 pcs',
                    'Cost' => 'Rs. 100',
                    'Selling price' => 'Rs. 130',
                ],
                'result' => 'The starting stock and price are clear.',
            ],
            [
                'title' => 'Keep it active and create it',
                'text' => 'Add a photo if helpful. Keep Active product checked if it should be sold. Click Create product and check the saved details.',
                'action' => 'Create product',
                'fields' => [
                    'Active product' => 'Yes',
                    'Starting stock' => '20 pcs',
                ],
                'result' => 'The new product is available at Point of sale.',
            ],
        ],
        'link' => [
            'route' => 'manage.create',
            'permission' => 'products.create',
            'parameters' => [
                'resource' => 'products',
            ],
        ],
    ],
    'adjust-stock' => [
        'title' => 'Correct a stock count',
        'category' => 'Products & stock',
        'description' => 'Record counted goods, missing goods or damaged items with a clear reason.',
        'path' => 'Products → New adjustment',
        'icon' => 'boxes',
        'minutes' => 2,
        'before' => '',
        'tip' => 'Use a purchase for deliveries and a return for returned goods. Use an adjustment for an actual correction, not to repeat those transactions.',
        'steps' => [
            [
                'title' => 'Count the physical goods',
                'text' => 'Count what is actually on the shelf and in storage. Compare it with the product’s current stock. Write down why it differs.',
                'action' => 'Count goods',
                'fields' => [
                    'System quantity' => '20 pcs',
                    'Counted quantity' => '18 pcs',
                ],
                'result' => 'You know the amount to correct.',
            ],
            [
                'title' => 'Enter the reason and add products',
                'text' => 'Open New adjustment. Enter a clear reason, then search or scan each product that needs a correction.',
                'action' => 'Add product',
                'fields' => [
                    'Reason' => 'Shelf count found 2 missing pieces',
                    'Product' => 'Bath Soap',
                ],
                'result' => 'The product appears in the adjustment table.',
            ],
            [
                'title' => 'Choose the correct adjustment',
                'text' => 'Add stock increases the current quantity. Remove stock decreases it. Set counted stock replaces it with the quantity you counted. For this example use Set counted stock and enter 18.',
                'action' => 'Set counted stock',
                'fields' => [
                    'Current stock' => '20 pcs',
                    'Counted stock' => '18 pcs',
                    'New stock' => '18 pcs',
                ],
                'result' => 'The preview shows the final count.',
            ],
            [
                'title' => 'Check and apply',
                'text' => 'Check the New stock column and the reason for every line. Change cost or price only if intended and allowed. Click Apply adjustment.',
                'action' => 'Apply adjustment',
                'fields' => [
                    'Reason' => 'Shelf count',
                    'New stock' => '18 pcs',
                ],
                'result' => 'The correction is saved in stock history.',
            ],
        ],
        'link' => [
            'route' => 'adjustments.create',
            'permission' => 'stock-adjustments.create',
            'parameters' => [],
        ],
    ],
    'units-and-categories' => [
        'title' => 'Organize products and units',
        'category' => 'Products & stock',
        'description' => 'Understand categories, pieces, weight and packs.',
        'path' => 'Categories · Units · Multiple units',
        'icon' => 'tags',
        'minutes' => 2,
        'before' => '',
        'tip' => 'Choose the main counting unit carefully when creating a product. Existing stock or sales can prevent changing it later.',
        'steps' => [
            [
                'title' => 'Use categories for shelves or product groups',
                'text' => 'Open Categories → Add category. Enter a useful name such as Beverages. Save it, then choose that category on the product form.',
                'action' => 'Save category',
                'fields' => [
                    'Category name' => 'Beverages',
                ],
                'result' => 'Related products are grouped together.',
            ],
            [
                'title' => 'Set up a counting unit',
                'text' => 'Open Units → Add unit. Enter a name and short name. Allow decimals for weight or volume when needed; whole pieces should normally not allow fractions.',
                'action' => 'Save unit',
                'fields' => [
                    'Unit name' => 'Kilogram',
                    'Short name' => 'kg',
                    'Decimals' => 'Allowed',
                ],
                'result' => 'A quantity such as 0.250 kg is valid.',
            ],
            [
                'title' => 'Understand a pack conversion',
                'text' => 'A conversion says how many base items are in another unit. Example: one box contains 12 pieces. Use the product’s multiple-unit options or a reusable Multiple units preset where available.',
                'action' => 'Set conversion',
                'fields' => [
                    'Base unit' => 'Piece',
                    '1 box equals' => '12 pieces',
                ],
                'result' => 'Selling one box removes 12 pieces from stock.',
            ],
            [
                'title' => 'Check before using it in sales',
                'text' => 'Review the product unit, pack quantity and price. Make a careful check on the product page before staff use the new pack at checkout.',
                'action' => 'Check product units',
                'fields' => [
                    'Selected unit' => 'Box',
                    'Quantity' => '1',
                    'Stock counted' => '12 pieces',
                ],
                'result' => 'The pack size agrees with the real goods.',
            ],
        ],
        'link' => [
            'route' => 'manage.index',
            'permission' => 'units.view',
            'parameters' => [
                'resource' => 'units',
            ],
        ],
    ],
    'customers-and-suppliers' => [
        'title' => 'Add customers and suppliers',
        'category' => 'People & payments',
        'description' => 'Keep names and phone numbers clear so payments reach the right account.',
        'path' => 'Customers · Suppliers',
        'icon' => 'contact',
        'minutes' => 2,
        'before' => '',
        'tip' => '',
        'steps' => [
            [
                'title' => 'Choose which person or business to add',
                'text' => 'Use Customers → Add customer for someone buying from you. Use Suppliers → Add supplier for a business delivering goods to you.',
                'action' => 'Choose Add customer',
                'fields' => [
                    'Type' => 'Customer',
                ],
                'result' => 'The correct form opens.',
            ],
            [
                'title' => 'Enter recognizable details',
                'text' => 'Enter the name and phone number. Check spelling and phone digits so staff can find the same account next time. Fill other details that apply.',
                'action' => 'Enter details',
                'fields' => [
                    'Name' => 'Example Customer',
                    'Phone' => 'Their phone number',
                ],
                'result' => 'Staff can identify the person reliably.',
            ],
            [
                'title' => 'Check any starting balance',
                'text' => 'If an opening balance field is shown, enter only a genuine unpaid balance from before using the system. Do not enter an amount already recorded by a bill or payment.',
                'action' => 'Review opening balance',
                'fields' => [
                    'Opening balance' => 'Rs. 0 unless already owed',
                ],
                'result' => 'Old balances are not counted twice.',
            ],
            [
                'title' => 'Save and use the account',
                'text' => 'Save the customer or supplier. Next time select this same account on the sale or purchase. Open their profile to check bills, payments and returns.',
                'action' => 'Save',
                'fields' => [
                    'Account' => 'Active',
                ],
                'result' => 'The account is ready to use.',
            ],
        ],
        'link' => [
            'route' => 'manage.index',
            'permission' => 'customers.view',
            'parameters' => [
                'resource' => 'customers',
            ],
        ],
    ],
    'record-an-expense' => [
        'title' => 'Record a shop expense',
        'category' => 'People & payments',
        'description' => 'Save a shop cost such as electricity, transport or cleaning.',
        'path' => 'Expenses → Add expense',
        'icon' => 'circle-dollar-sign',
        'minutes' => 2,
        'before' => '',
        'tip' => '',
        'steps' => [
            [
                'title' => 'Choose a useful expense category',
                'text' => 'Open Expenses → Add expense. Select the category that describes the cost. Add a category first if a suitable one is missing.',
                'action' => 'Choose category',
                'fields' => [
                    'Category' => 'Utilities',
                ],
                'result' => 'The cost can be grouped correctly in reports.',
            ],
            [
                'title' => 'Enter the amount and date',
                'text' => 'Enter the amount actually paid, the expense date and a clear description. Check where the payment came from using the options shown.',
                'action' => 'Enter expense',
                'fields' => [
                    'Amount' => 'Rs. 2,500',
                    'Description' => 'Shop electricity bill',
                ],
                'result' => 'The cost is clearly described.',
            ],
            [
                'title' => 'Review and save',
                'text' => 'Check the date, amount, category and payment details before saving. Keep your paper receipt for the shop records.',
                'action' => 'Save expense',
                'fields' => [
                    'Category' => 'Utilities',
                    'Amount' => 'Rs. 2,500',
                ],
                'result' => 'The expense is recorded.',
            ],
            [
                'title' => 'Check it in history',
                'text' => 'Open All expenses or the expense report to confirm it appears once. Do not enter a supplier purchase or an automatic return write-off again as another expense.',
                'action' => 'Check All expenses',
                'fields' => [
                    'Electricity bill' => 'Recorded once',
                ],
                'result' => 'Your cost history is clear.',
            ],
        ],
        'link' => [
            'route' => 'manage.create',
            'permission' => 'expenses.create',
            'parameters' => [
                'resource' => 'expenses',
            ],
        ],
    ],
    'read-reports' => [
        'title' => 'Read and print reports',
        'category' => 'Reports & management',
        'description' => 'Choose a period and understand sales, unpaid balances and profit.',
        'path' => 'Reports → All reports',
        'icon' => 'chart-no-axes-combined',
        'minutes' => 3,
        'before' => '',
        'tip' => 'If totals look different, check the date range, filters, returns and cancelled bills before asking your manager.',
        'steps' => [
            [
                'title' => 'Choose the report you need',
                'text' => 'Open Reports → All reports. Choose Sales for bills, Stock for current goods, Customer due for money still owed, or Profit & loss for the store’s earnings and costs. Available reports depend on your access.',
                'action' => 'Choose Sales report',
                'fields' => [
                    'Report' => 'Sales',
                ],
                'result' => 'The selected report opens.',
            ],
            [
                'title' => 'Choose the dates and filters',
                'text' => 'Set From and To, then use Filter. Choose a customer, product, cashier or payment method when that filter is available.',
                'action' => 'Filter',
                'fields' => [
                    'From' => 'Start of the period',
                    'To' => 'End of the period',
                ],
                'result' => 'The figures match the selected period.',
            ],
            [
                'title' => 'Read the amounts carefully',
                'text' => 'Sales value is what you sold. Due is what is still unpaid. Profit is what remains after goods costs and expenses. Stock reports show today’s stock, not a past stock count.',
                'action' => 'Read totals',
                'fields' => [
                    'Sales' => 'Rs. 10,000',
                    'Goods cost' => 'Rs. 7,000',
                    'Expenses' => 'Rs. 1,000',
                ],
                'result' => 'This example leaves Rs. 2,000 profit.',
            ],
            [
                'title' => 'Check return information',
                'text' => 'In Sales Returns Report, use Return type and Verification to separate bill returns from no-receipt returns. Check refunds and due credits separately. Estimated no-receipt costs are identified in the accounts.',
                'action' => 'Check return filters',
                'fields' => [
                    'Return type' => 'Without Bill',
                    'Verification' => 'Unverified',
                ],
                'result' => 'The return history keeps these differences clear.',
            ],
            [
                'title' => 'Download or print',
                'text' => 'Use Download PDF for a ready-to-print report, Export CSV for a table file, or Print where shown. Check the title and dates on your copy.',
                'action' => 'Download PDF',
                'fields' => [
                    'Report dates' => 'The period you selected',
                ],
                'result' => 'You have a copy of the report.',
            ],
        ],
        'link' => [
            'route' => 'reports.index',
            'permission' => null,
            'parameters' => [],
        ],
    ],
    'close-your-shift' => [
        'title' => 'Close your shift',
        'category' => 'Daily selling',
        'description' => 'Compare the recorded cash with the actual money in the drawer.',
        'path' => 'Daily register → Close register',
        'icon' => 'wallet',
        'minutes' => 2,
        'before' => '',
        'tip' => 'Opening cash, cash sales, refunds and register movements affect the drawer. Card payments do not become cash.',
        'steps' => [
            [
                'title' => 'Finish pending work',
                'text' => 'Finish or check the sale you are working on. Open Daily register or use Close register in Point of sale. Do not count card or bank payments as cash in the drawer.',
                'action' => 'Close register',
                'fields' => [
                    'Register' => 'Open',
                ],
                'result' => 'The shift summary is shown.',
            ],
            [
                'title' => 'Count the drawer',
                'text' => 'Count all physical notes and coins. Enter this total as Actual cash counted. Count it again if you are unsure.',
                'action' => 'Enter Actual cash counted',
                'fields' => [
                    'Actual cash counted' => 'Rs. 8,950',
                ],
                'result' => 'The system compares your count with expected cash.',
            ],
            [
                'title' => 'Understand any difference',
                'text' => 'Expected cash is what the recorded cash movements say should be in the drawer. Actual cash is what you counted. A negative difference means less cash was counted; a positive difference means extra cash was counted.',
                'action' => 'Check Cash difference',
                'fields' => [
                    'Expected cash' => 'Rs. 9,000',
                    'Actual cash' => 'Rs. 8,950',
                    'Difference' => '−Rs. 50',
                ],
                'result' => 'You can investigate the Rs. 50 difference.',
            ],
            [
                'title' => 'Add a note and close',
                'text' => 'Check cash sales, refunds, expenses and other cash movements if the count differs. Add a clear closing note if needed, then use the closing button.',
                'action' => 'Close register',
                'fields' => [
                    'Closing note' => 'Checked cash count with manager',
                ],
                'result' => 'The completed shift is kept in register history.',
            ],
        ],
        'link' => [
            'route' => 'register.index',
            'permission' => 'register.view',
            'parameters' => [],
        ],
    ],
    'store-settings' => [
        'title' => 'Change store settings',
        'category' => 'Reports & management',
        'description' => 'Understand what the settings do before changing them.',
        'path' => 'Settings → Overview',
        'icon' => 'settings-2',
        'minutes' => 3,
        'before' => '',
        'tip' => 'If a settings option is missing, your account may not have access. Ask a manager rather than using someone else’s login.',
        'steps' => [
            [
                'title' => 'Open the right settings card',
                'text' => 'Open Settings → Overview. Choose the card for the change you need: Business information, Point of sale, Receipt & printing, Stock, System or Returns.',
                'action' => 'Choose Business information',
                'fields' => [
                    'Settings card' => 'Business information',
                ],
                'result' => 'The relevant settings form opens.',
            ],
            [
                'title' => 'Set the shop details',
                'text' => 'Business information contains the store name, address, phone and logo. These details help identify the shop on documents.',
                'action' => 'Check business information',
                'fields' => [
                    'Business name' => 'Your store name',
                    'Telephone' => 'Your shop phone',
                ],
                'result' => 'Documents can show the correct shop details.',
            ],
            [
                'title' => 'Set receipt and checkout preferences',
                'text' => 'Receipt & printing controls paper width and receipt details. Choose the width your printer uses, such as 80mm or 58mm. Point of sale controls options such as scanner use and discounts.',
                'action' => 'Check paper width',
                'fields' => [
                    'Thermal paper width' => '80mm',
                    'Show logo' => 'On',
                ],
                'result' => 'The receipt settings match your printer.',
            ],
            [
                'title' => 'Review stock and return rules with the manager',
                'text' => 'Stock controls affect whether items with no stock can be sold. Returns controls refunds, approval and no-receipt returns. Only change these after agreeing how the shop should work.',
                'action' => 'Review Returns',
                'fields' => [
                    'Cash refund without receipt' => 'Off unless authorized',
                    'Manager approval' => 'According to shop policy',
                ],
                'result' => 'Staff follow the agreed return rules.',
            ],
            [
                'title' => 'Save your changes',
                'text' => 'Review the fields you changed, then click Save changes. Check the success message. If a page was already open, refresh it to load the new settings.',
                'action' => 'Save changes',
                'fields' => [
                    'Message' => 'Settings saved',
                ],
                'result' => 'The store uses the saved preferences.',
            ],
        ],
        'link' => [
            'route' => 'settings.index',
            'permission' => 'settings.view',
            'parameters' => [],
        ],
    ],
    'users-and-access' => [
        'title' => 'Give staff the right access',
        'category' => 'Reports & management',
        'description' => 'Create individual accounts and choose what each person can do.',
        'path' => 'Users · Roles & permissions',
        'icon' => 'shield-check',
        'minutes' => 2,
        'before' => '',
        'tip' => 'Permissions mean allowed actions. Knowing how to use a screen does not give permission to change it.',
        'steps' => [
            [
                'title' => 'Choose a suitable role',
                'text' => 'Open Roles & permissions and review an existing role, such as Cashier. A role is a set of allowed actions, such as making sales or viewing reports.',
                'action' => 'Review role',
                'fields' => [
                    'Role' => 'Cashier',
                    'Allowed work' => 'Selling and agreed return actions',
                ],
                'result' => 'The role matches the person’s job.',
            ],
            [
                'title' => 'Set only the needed permissions',
                'text' => 'Enable the actions that this job needs. For example, refunds, price changes and viewing costs can be limited to managers. Review sale visibility settings as well.',
                'action' => 'Review permissions',
                'fields' => [
                    'View costs' => 'Manager only if appropriate',
                    'Refunds' => 'Only authorized staff',
                ],
                'result' => 'The access rules are deliberate.',
            ],
            [
                'title' => 'Create the staff account',
                'text' => 'Open Users → Add user. Enter the person’s name, login details and role. Use a separate account for each staff member.',
                'action' => 'Save user',
                'fields' => [
                    'Name' => 'Example Cashier',
                    'Role' => 'Cashier',
                ],
                'result' => 'The new staff account is recorded.',
            ],
            [
                'title' => 'Check their available work',
                'text' => 'Have the staff member sign in with their own account and check the menus they need. If an option is missing, review their role. They can still use Documentation for instructions.',
                'action' => 'Check staff menus',
                'fields' => [
                    'Point of sale' => 'Available when allowed',
                    'Documentation' => 'Available to signed-in staff',
                ],
                'result' => 'They can do the work their role allows.',
            ],
        ],
        'link' => [
            'route' => 'manage.index',
            'permission' => 'roles.view',
            'parameters' => [
                'resource' => 'roles',
            ],
        ],
    ],
    'staff-and-payroll' => [
        'title' => 'Manage staff and salaries',
        'category' => 'Reports & management',
        'description' => 'Follow staff, attendance and salary records when Human resources is enabled.',
        'path' => 'Human resources',
        'icon' => 'users-round',
        'minutes' => 2,
        'before' => '',
        'tip' => 'This menu appears only when Human resources is enabled and your account has access. Ask the manager about your shop’s salary rules.',
        'steps' => [
            [
                'title' => 'Set up staff details',
                'text' => 'If Human resources appears in your menu, use Staff to add each worker. Check their employment and salary information before using payroll. HR setup contains the shop’s work and salary rules.',
                'action' => 'Open Staff',
                'fields' => [
                    'Staff member' => 'Example Worker',
                ],
                'result' => 'The worker has a staff record.',
            ],
            [
                'title' => 'Record attendance and leave',
                'text' => 'Use Attendance to record the working dates and status. Record and review leave requests using Leave requests. Check dates carefully.',
                'action' => 'Review attendance',
                'fields' => [
                    'Working day' => 'Correct date',
                    'Status' => 'Present or agreed status',
                ],
                'result' => 'The records reflect the person’s work.',
            ],
            [
                'title' => 'Prepare and review payroll',
                'text' => 'Payroll means the salary calculation for a period. Open Payroll, choose the appropriate period and review the earnings, deductions and amounts shown before approving or finalizing with the available controls.',
                'action' => 'Review Payroll',
                'fields' => [
                    'Period' => 'Selected salary period',
                    'Amount' => 'Check before approval',
                ],
                'result' => 'Salary calculations can be reviewed.',
            ],
            [
                'title' => 'Record payments and check history',
                'text' => 'Use Payments & advances for actual staff payments or advances. An advance is salary money paid early. Review the worker’s history and HR reports so payments are not entered twice.',
                'action' => 'Check Payments & advances',
                'fields' => [
                    'Payment' => 'Actual amount given',
                    'Reference' => 'A useful payment note',
                ],
                'result' => 'Staff payments remain traceable.',
            ],
        ],
    ],
    'common-questions' => [
        'title' => 'Solve common problems',
        'category' => 'Getting started',
        'description' => 'Simple checks for missing products, buttons, printing and balances.',
        'path' => 'Quick help',
        'icon' => 'info',
        'minutes' => 4,
        'before' => '',
        'tip' => 'You can pause any animated example and select a step to repeat it. The examples use made-up shop data; they do not save real transactions.',
        'steps' => [
            [
                'title' => 'A product is missing',
                'text' => 'Clear the product search and choose All products. Check that the product is active and has suitable stock. Scroll inside the product list. If necessary, ask a manager to check its details.',
                'action' => 'Choose All products',
                'fields' => [
                    'Search' => 'Empty',
                    'Category' => 'All products',
                ],
                'result' => 'You can check the full available product list.',
            ],
            [
                'title' => 'A price choice appears',
                'text' => 'Choose the price that matches the goods being sold. Different deliveries can have different selling prices. Do not assume the first price is always correct.',
                'action' => 'Choose the correct price',
                'fields' => [
                    'Price 1' => 'Rs. 130',
                    'Price 2' => 'Rs. 140',
                ],
                'result' => 'The intended price is used.',
            ],
            [
                'title' => 'A button or menu is missing',
                'text' => 'Your role may not allow that action, or a store setting may turn it off. Ask the manager to review your access. A cash refund also needs an open register.',
                'action' => 'Ask your manager',
                'fields' => [
                    'Check' => 'Role, settings and register status',
                ],
                'result' => 'The manager can confirm what is allowed.',
            ],
            [
                'title' => 'A receipt does not print',
                'text' => 'Open the saved sale and its receipt. Use Print or your browser’s print command. Check that the printer is powered on, connected and has paper. Check the selected printer and paper width.',
                'action' => 'Open receipt',
                'fields' => [
                    'Printer' => 'Correct printer',
                    'Paper' => '80mm or 58mm as configured',
                ],
                'result' => 'You can retry printing the saved receipt.',
            ],
            [
                'title' => 'You are unsure whether a sale saved',
                'text' => 'Check All sales for the saved invoice before making the same sale again. Compare the customer, time, items and amount. Do not repeat a payment just because a receipt did not appear.',
                'action' => 'Check All sales',
                'fields' => [
                    'Check' => 'Recent invoice and items',
                ],
                'result' => 'You avoid charging or recording the sale twice.',
            ],
            [
                'title' => 'An amount looks wrong',
                'text' => 'Check quantity, unit, price, discounts, payment charges and selected dates. For returns, also check original due reduction and any replacement value. If still unsure, show the bill number to the manager.',
                'action' => 'Check the bill details',
                'fields' => [
                    'Quantity' => 'Correct',
                    'Unit and price' => 'Correct',
                    'Payments and returns' => 'Reviewed',
                ],
                'result' => 'You can explain exactly which amount needs checking.',
            ],
        ],
    ],
];
