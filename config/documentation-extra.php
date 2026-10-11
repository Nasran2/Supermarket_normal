<?php

return [
    'default-product-discounts' => [
        'title' => 'Set a product default discount', 'category' => 'Products & stock', 'description' => 'Choose no discount, a fixed amount or a percentage for selected products.', 'path' => 'Products → Add product / Edit product → Default discount', 'icon' => 'percent', 'minutes' => 3, 'before' => 'Use your own account. Price changes may require manager access.', 'tip' => 'The discount applies automatically when the product is added to a sale. Check the cart before taking payment.',
        'steps' => [
            ['title' => 'Open the product', 'text' => 'Open Add product or Edit product and find Default discount. Keep the selling price as the normal price before the discount.'],
            ['title' => 'Choose None for no discount', 'text' => 'Choose None when this product should have no automatic discount. Other products can still have their own discounts.'],
            ['title' => 'Use a fixed amount once per line', 'text' => 'Choose Fixed amount and enter the amount, such as 10. Two units at Rs. 100 make Rs. 200 before discount. Rs. 10 is removed once from that whole line, so the customer pays Rs. 190. It is not Rs. 10 off each unit.'],
            ['title' => 'Use a percentage or save your choice', 'text' => 'Choose Percentage (%) and enter 10 for a 10% discount. On a Rs. 200 product line this removes Rs. 20, leaving Rs. 180. Check your choice, save the product, and check its discount in the POS cart.'],
        ], 'link' => ['route' => 'manage.index', 'permission' => 'products.view', 'parameters' => ['resource' => 'products']],
    ],
    'maintain-product-stock' => [
        'title' => 'Choose whether a product maintains stock', 'category' => 'Products & stock', 'description' => 'Keep a quantity for counted goods, or sell a product without a stock count.', 'path' => 'Products → Add product / Edit product → Maintain stock', 'icon' => 'package', 'minutes' => 2, 'before' => 'Maintain stock is enabled by default. Check existing stock before changing an existing product.', 'tip' => 'Turning stock maintenance off does not mean the item is free. Selling price, cost and discounts still apply.',
        'steps' => [
            ['title' => 'Leave it on for counted goods', 'text' => 'Keep Maintain stock ticked for goods you count, such as bottles or pieces. Choose the primary unit and enter the correct opening quantity when creating the product.'],
            ['title' => 'Turn it off only when no count is needed', 'text' => 'Untick Maintain stock for a service or another product sold without a stock count. Enter cost and selling price. The POS does not require available stock, and low-stock alerts do not apply to that product.'],
            ['title' => 'Review before saving', 'text' => 'Check the unit, price and discount, then save. Do not switch off stock maintenance just to hide a wrong quantity. Correct counted goods through a stock adjustment. Expiry tracking requires stock maintenance.'],
        ], 'link' => ['route' => 'manage.index', 'permission' => 'products.view', 'parameters' => ['resource' => 'products']],
    ],
    'expiry-batches' => [
        'title' => 'Manage expiry dates and batches', 'category' => 'Products & stock', 'description' => 'Enter batch dates, sell the correct stock and review expired products.', 'path' => 'Products → Track expiry · Reports → Expiry report', 'icon' => 'calendar-days', 'minutes' => 5, 'requires_expiry' => true, 'before' => 'Your server administrator must enable EXPIRE=true and clear the Laravel configuration cache. Staff need the relevant permissions.', 'tip' => 'Physical stock can be greater than sellable stock. Expired and unknown-date stock are blocked by default. A date equal to today is still valid for selling.',
        'steps' => [
            ['title' => 'Enable expiry on the product', 'text' => 'Keep Maintain stock on and tick Track expiry for products with dates on their packaging. Leave it off for other products.'],
            ['title' => 'Enter the date for each batch', 'text' => 'Enter the packaging date for every opening or received purchase batch. Use separate rows for different dates or prices. Enter a batch number if your shop uses one. New purchases must not already be expired and must expire after the receiving date.'],
            ['title' => 'Choose the correct stock at checkout', 'text' => 'The POS shows selling price, expiry date and available quantity together. Choose the date on the goods being sold. One available option is added automatically. If the selected date has too little stock, add the remaining goods from another batch separately.'],
            ['title' => 'Understand earliest expiry first', 'text' => 'In Settings → Expiry dates, a manager can choose earliest expiry first (FEFO). The system takes the earliest valid dates at the same selected price. It never silently combines different selling prices. Take the matching physical goods from the shelf.'],
            ['title' => 'Review alerts and reports', 'text' => 'Open Expiry report for soon, expired or all batches. Use the date, product, supplier and batch filters. Alerts normally cover 45 days, with 7 days marked urgent. PDF and CSV include every matching page. Cost values depend on permission.'],
            ['title' => 'Remove expired goods carefully', 'text' => 'Remove expired goods from the selling shelf. In the Expired report choose Write off, enter quantity and reason, and review before confirming. Stock is reduced and its inventory cost is recorded once as an expense. Verified customer returns use the original batch; expired returns cannot normally be restocked.'],
        ], 'link' => ['route' => 'expiry.index', 'permission' => 'expiry.view', 'parameters' => []],
    ],
];
